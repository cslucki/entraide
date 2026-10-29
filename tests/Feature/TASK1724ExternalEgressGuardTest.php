<?php

namespace Tests\Feature;

use App\Support\Ops\ExternalEgressGuard;
use App\Support\Ops\RefusingTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * TASK-1724 — rien ne sort d'un jumeau de PRODUCTION.
 *
 * ## Ce que ces gardes mesurent
 *
 * **Aucune requete reseau reelle.** Le garde refuse AVANT emission : c'est
 * precisement ce qui est prouve ici. Une tentative vers un hote externe leve
 * l'exception du garde sans qu'aucun socket ne soit ouvert.
 *
 * ## Pourquoi PAS `Http::fake()` ni `Mail::fake()`
 *
 * Consigne MASTER : « `Http::fake()` et `Mail::fake()` ne constituent pas, a
 * eux seuls, des preuves suffisantes ». Une doublure remplace le transport —
 * la garde serait alors VERTE que le garde existe ou non, et ne mesurerait
 * que la doublure. Le faux vert classique.
 *
 * On interroge donc les PREDICATS purs, qui portent la decision, et on
 * exerce le transport de refus directement. La preuve du bout en bout a ete
 * faite sur le jumeau vivant et est consignee au TASK file.
 */
class TASK1724ExternalEgressGuardTest extends TestCase
{
    // =====================================================================
    // A. LE PREDICAT — la decision, prouvee seule
    // =====================================================================

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function bases(): array
    {
        return [
            'le jumeau de travail' => [['driver' => 'pgsql', 'database' => 'bouclepro_prod_work'], true],
            'une baseline' => [['driver' => 'pgsql', 'database' => 'bouclepro_prod_baseline_1720_20261029'], true],
            'une repetition' => [['driver' => 'pgsql', 'database' => 'bouclepro_prod_rehearsal_20260917'], true],
            'la base de developpement' => [['driver' => 'pgsql', 'database' => 'bouclepro'], false],
            'la base de la CI' => [['driver' => 'pgsql', 'database' => 'bouclepro_test'], false],
            'sqlite en memoire' => [['driver' => 'sqlite', 'database' => ':memory:'], false],
            'nom vide' => [['driver' => 'pgsql', 'database' => ''], false],
            'reglage vide' => [[], false],
        ];
    }

    /**
     * « nom vide » et « reglage vide » valent FALSE, et c'est delibere : ne
     * pas reconnaitre un jumeau n'arme pas le garde, donc ne casse pas le
     * developpement local. Le fail-closed de cette TASK porte sur la
     * DESTINATION, pas sur la detection — voir les risques residuels du TASK
     * file.
     *
     * @param  array<string, mixed>  $reglage
     */
    #[DataProvider('bases')]
    public function test_the_predicate_recognises_a_production_twin(array $reglage, bool $attendu): void
    {
        $this->assertSame($attendu, ExternalEgressGuard::viseUnJumeau($reglage));
    }

    /** `DB_URL` gouverne quand il est la : la cle `database` ne doit pas blanchir un jumeau. */
    public function test_a_database_url_cannot_hide_a_twin(): void
    {
        $this->assertTrue(ExternalEgressGuard::viseUnJumeau([
            'driver' => 'pgsql',
            'url' => 'pgsql://personne@127.0.0.1:5432/bouclepro_prod_work',
            'database' => 'une_base_anodine',
        ]), "l'url designe le jumeau : c'est elle qui gouverne");
    }

    // =====================================================================
    // B. LES DESTINATIONS
    // =====================================================================

    /** @return array<string, array{0: string, 1: bool}> */
    public static function hotes(): array
    {
        return [
            'bouclage v4' => ['127.0.0.1', true],
            'bouclage nomme' => ['localhost', true],
            'bouclage v6' => ['::1', true],
            'sous-domaine local' => ['mailhog.localhost', true],
            'un fournisseur IA' => ['openrouter.ai', false],
            'une plage privee' => ['192.168.1.20', false],
            'un hote vide' => ['', false],
            'une ruse de prefixe' => ['127.0.0.1.exemple.com', false],
        ];
    }

    /**
     * Une plage PRIVEE vaut FALSE : `192.168.x` designe une autre machine du
     * reseau, et « local » doit vouloir dire « cette machine ».
     */
    #[DataProvider('hotes')]
    public function test_only_loopback_counts_as_local(string $hote, bool $attendu): void
    {
        $this->assertSame($attendu, ExternalEgressGuard::hoteEstLocal($hote));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function mailers(): array
    {
        return [
            'MailHog sur le bouclage' => [['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025], true],
            'le journal' => [['transport' => 'log'], true],
            'le tableau' => [['transport' => 'array'], true],
            'un smtp distant' => [['transport' => 'smtp', 'host' => 'smtp.exemple.com'], false],
            'un smtp sans hote' => [['transport' => 'smtp'], false],
            'ses' => [['transport' => 'ses'], false],
            'sendmail' => [['transport' => 'sendmail'], false],
            'un transport inconnu' => [['transport' => 'quelque_chose'], false],
            'aucun transport' => [[], false],
        ];
    }

    /**
     * FAIL-CLOSED : un transport inconnu, ou un `smtp` sans hote, est traite
     * comme DISTANT. « En cas de transport ou de destination incertains :
     * refuser l'emission. »
     *
     * @param  array<string, mixed>  $reglage
     */
    #[DataProvider('mailers')]
    public function test_only_a_local_mailer_is_allowed(array $reglage, bool $attendu): void
    {
        $this->assertSame($attendu, ExternalEgressGuard::mailerEstLocal($reglage));
    }

    // =====================================================================
    // C. LE TRANSPORT DE REFUS — exerce pour de vrai
    // =====================================================================

    /**
     * Le transport pose a la place d'un mailer distant REFUSE, et n'ouvre
     * aucune connexion : l'exception part avant tout reseau.
     */
    public function test_the_refusing_transport_throws_instead_of_sending(): void
    {
        $transport = new RefusingTransport('ses', 'bouclepro_prod_work');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/EMISSION REFUSEE/');

        $transport->send(new RawMessage('corps'));
    }

    /** Le message NOMME le mailer et la base : un refus muet serait indebogable. */
    public function test_the_refusal_names_the_mailer_and_the_database(): void
    {
        try {
            (new RefusingTransport('postmark', 'bouclepro_prod_baseline_1720_20261029'))
                ->send(new RawMessage('corps'));
            $this->fail('le transport aurait du refuser');
        } catch (RuntimeException $refus) {
            $this->assertStringContainsString('postmark', $refus->getMessage());
            $this->assertStringContainsString('bouclepro_prod_baseline_1720_20261029', $refus->getMessage());
        }
    }

    // =====================================================================
    // D. LE DEVELOPPEMENT LOCAL ORDINAIRE N'EST PAS GENE
    // =====================================================================

    /**
     * Le banc tourne sur sqlite : le garde ne s'arme pas, et les facades
     * restent telles que la suite les attend.
     *
     * Sans cette garde, un durcissement du predicat casserait les 849 classes
     * de tests sans qu'on sache pourquoi.
     */
    public function test_the_guard_stays_silent_outside_a_twin(): void
    {
        $connexion = (string) config('database.default');

        $this->assertFalse(
            ExternalEgressGuard::viseUnJumeau((array) config("database.connections.{$connexion}")),
            'le banc de test ne doit jamais etre pris pour un jumeau'
        );

        $this->assertNotSame(
            'bouclepro-refused',
            config('mail.mailers.'.config('mail.default').'.transport'),
            'aucun mailer du banc ne doit avoir ete remplace'
        );

        // Et la facade Http reste utilisable : `Tests\TestCase` arme deja
        // `preventStrayRequests()`, donc une doublure est ici le seul moyen
        // de verifier que rien d'AUTRE ne s'interpose.
        Http::fake(['exemple.test/*' => Http::response('ok', 200)]);
        $this->assertSame('ok', Http::get('https://exemple.test/x')->body());
    }
}
