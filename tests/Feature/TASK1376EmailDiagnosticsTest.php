<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Ops\MailDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1376 — le diagnostic email de l'administration.
 *
 * ## La question a laquelle cet ecran repond
 *
 * « smtp » ne dit pas si l'on parle a un serveur de capture local ou a un vrai
 * relais qui enverra pour de bon. C'est pourtant la seule chose qu'on veut
 * savoir avant de cliquer sur « envoyer un test ».
 *
 * ## Ce qui ne doit JAMAIS apparaitre
 *
 * Aucun identifiant, aucun mot de passe, aucune cle d'API — **meme masques**. Un
 * masque dit deja qu'un secret existe et quelle est sa longueur, et il se retire
 * par accident au refactor suivant. Le test le verifie avec des sentinelles
 * figees, pas avec des valeurs de configuration reelles.
 */
class TASK1376EmailDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINELLE_USER = 'Zzyzx-Mail-User-Sentinel';

    private const SENTINELLE_PASS = 'Zzyzx-Mail-Pass-Sentinel';

    private MailDiagnostics $diagnostics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diagnostics = app(MailDiagnostics::class);

        // Aucune sonde reseau ne doit partir depuis un test.
        Http::preventStrayRequests();

        // L'adresse de l'interface est POSEE, jamais empruntee a la machine.
        // Un `.env` local peut la deplacer — c'est meme sa raison d'etre — et un
        // test qui la lirait mesurerait le poste de developpement plutot que le
        // code. Les tests qui la font varier la reposent eux-memes.
        config(['services.mailhog.ui_url' => 'http://127.0.0.1:8025']);
    }

    // =====================================================================
    // 1. Le verdict
    // =====================================================================

    /** Un hote de boucle locale en smtp : capture locale. */
    public function test_a_loopback_smtp_host_reads_as_local_capture(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);

        $t = $this->diagnostics->transport();

        $this->assertSame('SAFE LOCAL / MAILHOG', $t['badge']);
        $this->assertTrue($t['is_local_capture']);
    }

    /** Le driver `log` aussi : rien ne sort de la machine. */
    public function test_the_log_driver_reads_as_local(): void
    {
        config(['mail.default' => 'log']);

        $t = $this->diagnostics->transport();

        $this->assertSame('SAFE LOCAL / LOG', $t['badge']);
        $this->assertTrue($t['is_local_capture']);
    }

    /**
     * **Un hote distant est annonce comme tel, sans nuance.**
     *
     * C'est le seul cas ou un email de test partira reellement, donc le seul que
     * l'administrateur doit voir sans avoir a lire quoi que ce soit d'autre.
     */
    public function test_a_remote_smtp_host_reads_as_an_active_external_relay(): void
    {
        $this->transportSmtp('smtp.exemple-relais.test', 587);

        $t = $this->diagnostics->transport();

        $this->assertSame('SMTP EXTERNE ACTIF', $t['badge']);
        $this->assertFalse($t['is_local_capture']);
    }

    /** `localhost` et `::1` comptent aussi comme boucle locale. */
    public function test_other_loopback_spellings_are_recognised(): void
    {
        foreach (['localhost', '::1', 'LOCALHOST'] as $hote) {
            $this->transportSmtp($hote, 1025);

            $this->assertSame('SAFE LOCAL / MAILHOG', $this->diagnostics->transport()['badge'], "[{$hote}]");
        }
    }

    // =====================================================================
    // 2. Aucun secret, meme masque
    // =====================================================================

    /** Le diagnostic ne rend jamais l'identifiant ni le mot de passe. */
    public function test_the_diagnostics_never_return_credentials(): void
    {
        $this->transportSmtp('smtp.exemple-relais.test', 587, self::SENTINELLE_USER, self::SENTINELLE_PASS);

        $t = $this->diagnostics->transport();
        $serialise = json_encode($t);

        // On mesure des FRAGMENTS, pas les valeurs entieres. Un masque du type
        // « Zzyzx-… » ne contient pas la valeur complete : asserter celle-ci
        // laisserait passer exactement la fuite que cette classe existe pour
        // empecher. Mesure verifiee par sabotage.
        foreach ([self::SENTINELLE_USER, self::SENTINELLE_PASS] as $secret) {
            $this->assertStringNotContainsString(substr($secret, 0, 5), $serialise, 'Aucun fragment de secret, meme masque.');
        }

        // Et aucune cle du diagnostic ne doit evoquer un secret.
        foreach (array_keys($t) as $champ) {
            $this->assertDoesNotMatchRegularExpression(
                '/pass|secret|token|credential|key/i',
                $champ,
                "Le champ [{$champ}] n'a rien a faire dans un diagnostic."
            );
        }

        // Seul le FAIT qu'une authentification existe est expose.
        $this->assertTrue($t['authenticated']);
    }

    /** Et la page non plus — c'est la garde qui compte vraiment. */
    public function test_the_admin_page_never_prints_credentials(): void
    {
        $this->transportSmtp('smtp.exemple-relais.test', 587, self::SENTINELLE_USER, self::SENTINELLE_PASS);

        $html = $this->actingAs($this->platformAdmin())->get(route('admin.email-test'))
            ->assertOk()
            ->getContent();

        // Fragments, pas valeurs entieres : « Zzyzx- » suffit a trahir la
        // presence d'un masque.
        foreach ([self::SENTINELLE_USER, self::SENTINELLE_PASS] as $secret) {
            $this->assertStringNotContainsString(substr($secret, 0, 5), $html);
        }
        $this->assertStringContainsString('data-mail-auth="oui"', $html);
    }

    /**
     * **La page ne NOMME aucune variable d'identifiant, et ne publie aucun hote
     * de production.**
     *
     * Ce defaut a existe : la page recopiait une recette `.env` de production,
     * host SMTP compris, et nommait les variables d'identifiant, de mot de passe
     * et de cle d'API. Aucune valeur ne fuyait — elles etaient vides — et c'est
     * precisement ce qui rendait la chose invisible aux mesures d'alors, qui ne
     * cherchaient que des VALEURS.
     *
     * Un nom de variable dit quelle porte pousser ; un host dit laquelle. Cette
     * mesure porte donc sur le VOCABULAIRE de la page, pas sur ses valeurs.
     */
    public function test_the_page_names_no_credential_variable_and_no_production_host(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);

        $html = $this->actingAs($this->platformAdmin())->get(route('admin.email-test'))
            ->assertOk()
            ->getContent();

        foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'RESEND_KEY', 'RESEND_API_KEY', 'MAIL_ENCRYPTION'] as $variable) {
            $this->assertStringNotContainsString($variable, $html, "La page ne doit pas nommer [{$variable}].");
        }

        // Et aucun hote de relais reel : une page d'administration n'a pas a
        // documenter l'infrastructure d'envoi de la production.
        $this->assertStringNotContainsString('relai-smtp', $html);
        $this->assertDoesNotMatchRegularExpression('/smtp\.[a-z0-9-]+\.(net|com|io|fr)/i', $html);
    }

    /** Sans authentification configuree, l'ecran le dit aussi. */
    public function test_the_page_states_when_no_authentication_is_configured(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);

        $this->actingAs($this->platformAdmin())->get(route('admin.email-test'))
            ->assertOk()
            ->assertSee('data-mail-auth="non"', false);
    }

    // =====================================================================
    // 3. La sonde est bornee
    // =====================================================================

    /**
     * **La sonde ne part PAS quand l'hote n'est pas local**, meme en
     * environnement local.
     *
     * `Http::preventStrayRequests()` fait echouer bruyamment toute requete non
     * simulee : si la sonde partait, ce test rougirait. C'est ce qui le rend
     * discriminant plutot que decoratif.
     */
    public function test_the_probe_does_not_fire_for_a_remote_host(): void
    {
        $this->transportSmtp('smtp.exemple-relais.test', 587);

        $this->assertNull($this->diagnostics->mailhogMessageCount());
        $this->assertNull($this->diagnostics->mailhogUrl());
    }

    /** Ni hors environnement local, meme sur un hote de boucle locale. */
    public function test_the_probe_does_not_fire_outside_the_local_environment(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);
        app()->detectEnvironment(fn () => 'production');

        $this->assertNull($this->diagnostics->mailhogMessageCount());
        $this->assertNull($this->diagnostics->mailhogUrl());
    }

    /** Quand elle est permise, elle vise une adresse CONSTANTE. */
    public function test_when_allowed_the_probe_targets_a_constant_address(): void
    {
        // `localhost` est bien une boucle locale, donc la sonde est permise —
        // mais une URL CONSTRUITE depuis la configuration viserait
        // `http://localhost:8025`, pas la constante. C'est ce qui rend cette
        // mesure discriminante plutot que tautologique.
        $this->transportSmtp('localhost', 1025);
        app()->detectEnvironment(fn () => 'local');

        Http::fake(['127.0.0.1:8025/api/v2/messages' => Http::response(['total' => 7], 200)]);

        $this->assertSame(7, $this->diagnostics->mailhogMessageCount());
        $this->assertSame('http://127.0.0.1:8025', $this->diagnostics->mailhogUrl());

        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:8025/api/v2/messages');
    }

    /**
     * **Le LIEN se configure ; la SONDE, non.**
     *
     * Sous WSL les deux ne sont pas a la meme adresse : la sonde part du serveur,
     * donc de l'interieur de WSL, ou `127.0.0.1` est correct ; le lien est
     * ouvert depuis Windows, ou `127.0.0.1` designe une AUTRE machine. Un lien
     * fige sur la boucle locale y serait simplement mort.
     *
     * Ce test mesure les deux ENSEMBLE : deplacer le lien ne doit rien deplacer
     * de la sonde. C'est ce qui empeche qu'un « on rend l'adresse configurable »
     * ne devienne, au refactor suivant, une URL de requete sortante pilotable.
     */
    public function test_the_link_follows_configuration_while_the_probe_stays_constant(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);
        app()->detectEnvironment(fn () => 'local');
        config(['services.mailhog.ui_url' => 'http://172.27.130.89:8025']);

        Http::fake(['127.0.0.1:8025/*' => Http::response(['total' => 1], 200)]);

        $this->assertSame('http://172.27.130.89:8025', $this->diagnostics->mailhogUrl());
        $this->assertSame(1, $this->diagnostics->mailhogMessageCount());

        // La sonde n'a PAS suivi la configuration.
        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:8025/api/v2/messages');
    }

    /** Une adresse de configuration mal ecrite ne produit pas un `href` douteux. */
    public function test_a_malformed_configured_link_falls_back_to_the_loopback(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);
        app()->detectEnvironment(fn () => 'local');

        foreach (['javascript:alert(1)', 'pas-une-url', ''] as $mauvaise) {
            config(['services.mailhog.ui_url' => $mauvaise]);

            $this->assertSame('http://127.0.0.1:8025', $this->diagnostics->mailhogUrl(), "[{$mauvaise}]");
        }
    }

    /** MailHog eteint est un etat NORMAL : la page ne tombe pas. */
    public function test_an_unreachable_mailhog_is_not_an_error(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);
        app()->detectEnvironment(fn () => 'local');

        // Un 500 n'emprunte pas le meme chemin qu'une connexion refusee : le
        // premier rend une reponse, la seconde LEVE. Les deux doivent aboutir au
        // meme resultat cote ecran, donc les deux sont mesures.
        Http::fake(['127.0.0.1:8025/*' => Http::response('', 500)]);
        $this->assertNull($this->diagnostics->mailhogMessageCount());

        Http::fake(['127.0.0.1:8025/*' => fn () => throw new ConnectionException('refuse')]);
        $this->assertNull($this->diagnostics->mailhogMessageCount(), 'Une connexion refusee ne doit pas remonter.');

        $this->actingAs($this->platformAdmin())->get(route('admin.email-test'))
            ->assertOk()
            ->assertSee('data-mailhog-unreachable', false);
    }

    // =====================================================================
    // 4. L'ecran
    // =====================================================================

    /** Le lien MailHog n'existe QUE lorsque la sonde est permise. */
    public function test_the_mailhog_link_only_exists_when_the_probe_is_allowed(): void
    {
        $this->transportSmtp('smtp.exemple-relais.test', 587);

        $this->actingAs($this->platformAdmin())->get(route('admin.email-test'))
            ->assertOk()
            ->assertDontSee('data-mailhog-link', false)
            ->assertSee('data-mail-badge="SMTP EXTERNE ACTIF"', false);
    }

    /** L'ecran expose l'environnement, le driver, l'hote et le port. */
    public function test_the_screen_states_the_transport(): void
    {
        $this->transportSmtp('127.0.0.1', 1025);
        app()->detectEnvironment(fn () => 'local');
        Http::fake(['127.0.0.1:8025/*' => Http::response(['total' => 3], 200)]);

        $this->actingAs($this->platformAdmin())->get(route('admin.email-test'))
            ->assertOk()
            ->assertSee('data-mail-mailer', false)
            ->assertSee('data-mail-host', false)
            ->assertSee('data-mail-port', false)
            ->assertSee('data-mailhog-count="3"', false)
            ->assertSee('data-mail-badge="SAFE LOCAL / MAILHOG"', false);
    }

    /** Un non-administrateur n'y accede pas. */
    public function test_a_plain_member_cannot_reach_the_page(): void
    {
        $membre = User::factory()->create(['is_admin' => false]);

        $reponse = $this->actingAs($membre)->get(route('admin.email-test'));

        $this->assertContains($reponse->status(), [302, 403, 404], 'Un membre ordinaire ne doit pas y acceder.');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function transportSmtp(string $host, int $port, ?string $username = null, ?string $password = null): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
        ]);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
