<?php

namespace Tests\Feature;

use App\Support\Ops\DestructiveDatabaseGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1722 — sous PHPUnit, aucune ecriture n'atteint une base de travail.
 *
 * ## Ce que ces gardes mesurent, et ce qu'elles ne font JAMAIS
 *
 * Elles n'executent aucune commande destructrice et n'exigent AUCUNE
 * infrastructure : la connexion reste sqlite en memoire, on lui fait
 * seulement DECLARER le pilote et le nom d'une base de travail. Les
 * instructions s'executent donc pour de vrai, et le garde doit refuser
 * l'ecriture tout en laissant passer la lecture.
 *
 * Premier jet corrige : ces gardes visaient une base jetable LOCALE. Elles
 * passaient ici et ont rougi la CI, qui n'a pas cette base — une garde de
 * securite qui exige l'infrastructure de son auteur ne protege personne
 * d'autre que lui.
 *
 * Consigne MASTER : « prouver le refus des chemins dangereux sans jamais les
 * executer sur une vraie base de developpement ».
 *
 * ## Pourquoi pas `Artisan::call('migrate:fresh')`
 *
 * Mesure : ni `Artisan::call()` ni `$this->artisan()` ne diffusent
 * `CommandStarting` en processus. Une garde ecrite sur cette porte aurait ete
 * VERTE sans rien prouver — le faux vert classique. On vise donc la couche qui
 * protege reellement : `Connection::beforeExecuting()`.
 *
 * ## L'incident ferme
 *
 * `php artisan test --env=testing` resout `APP_ENV` a **local**, pas
 * `testing` : `phpunit.xml` n'est plus applique, `ArtisanDatabaseGuard` n'a
 * aucune attente pour `local`, et le garde de `Tests\TestCase` est ecrase des
 * que la classe enfant ecrit `use RefreshDatabase;` — 683 des 849 classes du
 * depot. La detection employee ici ne lit donc PAS l'environnement : elle
 * constate la presence de PHPUnit dans le processus.
 */
class TASK1722DestructiveDatabaseGuardTest extends TestCase
{
    /**
     * Le nom d'une base de TRAVAIL, porte par une connexion sqlite reelle.
     *
     * ## Pourquoi ce montage plutot qu'une vraie base PostgreSQL
     *
     * Le premier jet de ces gardes visait une base jetable locale. Elles
     * passaient ici et ont ROUGI la CI, qui n'a evidemment pas cette base :
     * une garde de securite qui exige une infrastructure locale ne protege
     * personne d'autre que son auteur.
     *
     * On fabrique donc une connexion qui MENT sur son identite — meme procede
     * que `TASK1367ArtisanDatabaseGuardTest` : le moteur reste sqlite en
     * memoire, donc les instructions s'executent pour de vrai, mais le pilote
     * et le nom declares sont ceux d'une base de travail. Le garde lit la
     * configuration, voit `pgsql` / `bouclepro`, et doit refuser.
     *
     * Ce montage prouve les deux moities d'un coup, partout, sans serveur :
     * l'ecriture est refusee, et la lecture passe.
     */
    private const NOM_DE_TRAVAIL = 'bouclepro';

    /**
     * Une connexion sqlite reelle qui se DECLARE pgsql sur une base de travail.
     */
    private function connexionDeguisee(string $base): Connection
    {
        $connexion = DB::connection();

        $propriete = new \ReflectionProperty(Connection::class, 'config');
        $config = $propriete->getValue($connexion);

        $this->configOrigine ??= $config;

        $config['driver'] = 'pgsql';
        $config['database'] = $base;
        $propriete->setValue($connexion, $config);

        // Le garde s'arme a l'etablissement : on rejoue l'evenement sur la
        // connexion devenue suspecte.
        Event::dispatch(
            new ConnectionEstablished($connexion)
        );

        return $connexion;
    }

    private ?array $configOrigine = null;

    protected function tearDown(): void
    {
        if ($this->configOrigine !== null) {
            $propriete = new \ReflectionProperty(Connection::class, 'config');
            $propriete->setValue(DB::connection(), $this->configOrigine);
            $this->configOrigine = null;
        }

        parent::tearDown();
    }

    /**
     * LE cas du 28/10 : un test qui ecrit dans une base de travail.
     *
     * L'assertion porte sur le REFUS, pas sur le libelle d'une couche
     * precise. Deux gardes INDEPENDANTS couvrent ce geste, et lequel parle en
     * premier depend de l'environnement :
     *
     * - sous `bin/test`, `APP_ENV` vaut `testing` : `ArtisanDatabaseGuard`
     *   (TASK-1367) voit la contradiction « testing annonce, pgsql vise » ;
     * - sous `php artisan test --env=testing`, `APP_ENV` retombe a `local` :
     *   ce garde-la se TAIT, et seule la couche de TASK-1722 subsiste.
     *
     * Asserter le libelle du premier aurait rendu la garde muette sur le seul
     * cas qui a detruit la base.
     */
    public function test_a_test_cannot_write_to_a_work_database(): void
    {
        $connexion = $this->connexionDeguisee(self::NOM_DE_TRAVAIL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(ECRITURE DE BANC REFUSEE|ECRITURE REFUSEE)/');

        $connexion->statement('create table t1722_jamais (id integer)');
    }

    /**
     * La LECTURE reste possible : le garde protege la donnee, il ne coupe pas
     * la connexion. Sans cela, tout diagnostic deviendrait impossible.
     */
    public function test_reading_a_work_database_stays_possible(): void
    {
        $connexion = $this->connexionDeguisee(self::NOM_DE_TRAVAIL);

        $this->assertSame(1, (int) $connexion->selectOne('select 1 as n')->n);
    }

    /**
     * `DB_URL` gouverne quand il est la, et la cle `database` peut alors
     * designer tout autre chose.
     *
     * On interroge la REGLE, pas l'exception finale : les deux gardes du
     * depot s'arment dans un ordre qui depend de l'environnement, et
     * `ArtisanDatabaseGuard` rapporte le nom non resolu. Passer par le message
     * aurait teste « lequel a parle en premier ».
     */
    public function test_a_database_url_cannot_smuggle_a_work_database_past_the_rule(): void
    {
        $leurre = [
            'driver' => 'pgsql',
            'url' => 'pgsql://personne@127.0.0.1:5432/'.self::NOM_DE_TRAVAIL,
            // Le nom de banc que lirait un garde naif.
            'database' => 'quelque_chose_test',
        ];

        $this->assertFalse(
            DestructiveDatabaseGuard::viseUnBanc($leurre),
            "l'url designe une base de travail : le leurre `database` ne doit pas la blanchir"
        );
    }

    /** La reciproque : une url qui designe VRAIMENT un banc passe. */
    public function test_a_database_url_pointing_at_a_test_database_is_allowed(): void
    {
        $this->assertTrue(DestructiveDatabaseGuard::viseUnBanc([
            'driver' => 'pgsql',
            'url' => 'pgsql://personne@127.0.0.1:5432/bouclepro_test',
            'database' => 'bouclepro',
        ]), "l'url designe un banc : c'est elle qui gouverne, pas la cle `database`");
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function reglages(): array
    {
        return [
            'sqlite en memoire' => [['driver' => 'sqlite', 'database' => ':memory:'], true],
            'sqlite fichier' => [['driver' => 'sqlite', 'database' => '/tmp/banc.sqlite'], true],
            'la base de la CI' => [['driver' => 'pgsql', 'database' => 'bouclepro_test'], true],
            'la base de travail' => [['driver' => 'pgsql', 'database' => 'bouclepro'], false],
            'un jumeau PROD' => [['driver' => 'pgsql', 'database' => 'bouclepro_prod_baseline_1714_20261028'], false],
            'la PROD' => [['driver' => 'pgsql', 'database' => 'entraide'], false],
            'nom vide' => [['driver' => 'pgsql', 'database' => ''], false],
            'reglage vide' => [[], false],
        ];
    }

    /**
     * La table de verite de la regle, cas par cas.
     *
     * « nom vide » et « reglage vide » valent FALSE : fail-closed. Ne pas
     * savoir ce qu'on vise n'autorise pas a ecrire.
     *
     * @param  array<string, mixed>  $reglage
     */
    #[DataProvider('reglages')]
    public function test_the_rule_classifies_each_target(array $reglage, bool $attendu): void
    {
        $this->assertSame($attendu, DestructiveDatabaseGuard::viseUnBanc($reglage));
    }

    /** sqlite est toujours un banc : la suite entiere en depend. */
    public function test_sqlite_is_always_allowed(): void
    {
        DB::statement('create table if not exists t1722_sqlite_ok (id integer)');
        DB::statement('drop table if exists t1722_sqlite_ok');

        $this->assertTrue(true, 'le banc sqlite ecrit librement');
    }

    /** Le marqueur d'intention existe et porte un nom stable. */
    public function test_the_intent_markers_are_declared(): void
    {
        $this->assertSame('BOUCLEPRO_DESTRUCTIVE_DB_APPROVED', DestructiveDatabaseGuard::APPROVAL_MARKER);
        $this->assertTrue(method_exists(DestructiveDatabaseGuard::class, 'declarerIntention'));
    }
}
