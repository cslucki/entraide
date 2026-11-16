<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Rules\TurnstileValide;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * TASK-1837 — Cloudflare Turnstile a l'ENTREE du LOGIN (`/login`,
 * `/org/{slug}/login`).
 *
 * Suite directe de l'audit Sonnet (session « audit login », `develop`
 * `ac3e5578`) : TASK-1713 n'avait jamais couvert le login, seulement
 * l'inscription. Ce fichier reprend STRICTEMENT le motif de
 * `TASK1713TurnstileInscriptionTest` — memes trois moities, memes cles de
 * test Cloudflare officielles, meme `Http::preventStrayRequests()` — en
 * l'appliquant au login, et en prouvant en plus ce qui est NOUVEAU ici :
 * un DRAPEAU SEPARE (`TURNSTILE_LOGIN_ENABLED`) qui n'a aucune incidence sur
 * l'inscription, ni l'inverse.
 */
class TASK1837LoginTurnstileTest extends TestCase
{
    use RefreshDatabase;

    private const SITEKEY_QUI_PASSE = '1x00000000000000000000AA';

    private const SECRET_QUI_PASSE = '1x0000000000000000000000000000000AA';

    private const SECRET_QUI_ECHOUE = '2x0000000000000000000000000000000AA';

    private const JETON_FACTICE = 'XXXX.DUMMY.TOKEN.XXXX';

    private const MOT_DE_PASSE = 'MotDePasse!2026';

    private const URL_SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private User $utilisateur;

    private Organization $organisationPublique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationPublique = Organization::factory()->create([
            'slug' => 'org-1837',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->utilisateur = User::factory()->create([
            'email' => 'membre-1837@example.test',
            'password' => Hash::make(self::MOT_DE_PASSE),
            'organization_id' => $this->organisationPublique->id,
        ]);
    }

    private function allumerLogin(string $secret = self::SECRET_QUI_PASSE): void
    {
        config()->set('services.turnstile.login_enabled', true);
        config()->set('services.turnstile.site_key', self::SITEKEY_QUI_PASSE);
        config()->set('services.turnstile.secret_key', $secret);
    }

    private function eteindreLogin(): void
    {
        config()->set('services.turnstile.login_enabled', false);
    }

    /** @param array<string, mixed> $surcharges */
    private function identifiants(array $surcharges = []): array
    {
        return array_merge([
            'email' => $this->utilisateur->email,
            'password' => self::MOT_DE_PASSE,
            'organization' => $this->organisationPublique->slug,
        ], $surcharges);
    }

    /**
     * Memes identifiants, SANS le champ `organization` : c'est la forme
     * attendue sur `/org/{slug}/login`, ou l'Organization vient de la route
     * et un champ poste serait de toute facon ignore (`LoginRequest`).
     *
     * @param array<string, mixed> $surcharges
     */
    private function identifiantsSansOrganization(array $surcharges = []): array
    {
        $identifiants = $this->identifiants($surcharges);
        unset($identifiants['organization']);

        return $identifiants;
    }

    private function cloudflareRepond(bool $succes, array $codes = []): void
    {
        Http::fake([
            self::URL_SITEVERIFY => Http::response(
                ['success' => $succes] + ($codes !== [] ? ['error-codes' => $codes] : []),
                200,
            ),
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. ETEINT : le login fonctionne exactement comme avant
    // ───────────────────────────────────────────────────────────────────────

    public function test_captcha_login_eteint_la_connexion_globale_fonctionne_sans_jeton(): void
    {
        $this->eteindreLogin();

        $reponse = $this->post(route('login'), $this->identifiants());

        $reponse->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->utilisateur);
    }

    public function test_captcha_login_eteint_la_connexion_organization_fonctionne_sans_jeton(): void
    {
        $this->eteindreLogin();

        $reponse = $this->post(
            route('organization.login', $this->organisationPublique),
            $this->identifiantsSansOrganization(),
        );

        $reponse->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->utilisateur);
    }

    public function test_captcha_login_eteint_le_formulaire_ne_porte_aucun_widget(): void
    {
        $this->eteindreLogin();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="turnstile"', $html);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $html);
    }

    /**
     * Le drapeau de l'INSCRIPTION reste sans effet sur le login : ce sont
     * deux portes, et c'est le point de TASK-1837.
     */
    public function test_captcha_inscription_allume_sans_toucher_au_login(): void
    {
        config()->set('services.turnstile.enabled', true);
        config()->set('services.turnstile.site_key', self::SITEKEY_QUI_PASSE);
        config()->set('services.turnstile.secret_key', self::SECRET_QUI_PASSE);
        $this->eteindreLogin();
        Http::fake();

        $reponse = $this->post(route('login'), $this->identifiants());

        $reponse->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->utilisateur);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. ALLUME : ce qui doit etre refuse l'est
    // ───────────────────────────────────────────────────────────────────────

    public function test_captcha_login_allume_un_jeton_absent_refuse_la_connexion(): void
    {
        $this->allumerLogin();
        Http::fake();

        $reponse = $this->post(route('login'), $this->identifiants());

        $reponse->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();

        // Jeton absent = refuse SANS appeler Cloudflare (required implicite).
        Http::assertNothingSent();
    }

    public function test_captcha_login_allume_un_jeton_refuse_par_cloudflare_refuse_la_connexion(): void
    {
        $this->allumerLogin(self::SECRET_QUI_ECHOUE);
        $this->cloudflareRepond(false, ['invalid-input-response']);

        $reponse = $this->post(route('login'), $this->identifiants(['cf-turnstile-response' => 'jeton-bidon']));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_invalide'),
        ]);
        $this->assertGuest();
    }

    /**
     * Fail-closed : meme garde que l'inscription (TASK-1713), appliquee au
     * login. Un mot de passe CORRECT ne doit pas suffire : le captcha
     * tranche AVANT `Auth::attempt()`.
     */
    public function test_captcha_login_allume_cloudflare_injoignable_refuse_la_connexion(): void
    {
        $this->allumerLogin();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $reponse = $this->post(route('login'), $this->identifiants(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_indisponible'),
        ]);
        $this->assertGuest();
    }

    public function test_drapeau_login_allume_sans_cle_secrete_refuse_et_ne_se_desactive_pas(): void
    {
        config()->set('services.turnstile.login_enabled', true);
        config()->set('services.turnstile.site_key', self::SITEKEY_QUI_PASSE);
        config()->set('services.turnstile.secret_key', null);
        Http::fake();

        $this->assertTrue(app(TurnstileVerifier::class)->loginEnabled(), 'Le drapeau login s\'est desactive tout seul.');

        $reponse = $this->post(route('login'), $this->identifiants(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_indisponible'),
        ]);
        $this->assertGuest();
        Http::assertNothingSent();
    }

    /**
     * Rejeu d'un jeton : la regle n'a aucune memoire locale d'un jeton deja
     * consomme — c'est Cloudflare qui porte cette garantie (jeton a usage
     * unique, cote Siteverify). Mesure ici : un SECOND essai avec le MEME
     * jeton, alors que Cloudflare le refuse desormais (`timeout-or-duplicate`),
     * est bien refuse par la regle — la garde ne retombe pas sur un succes
     * mis en cache localement.
     */
    public function test_un_jeton_rejoue_que_cloudflare_refuse_desormais_refuse_la_connexion(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(false, ['timeout-or-duplicate']);

        $reponse = $this->post(route('login'), $this->identifiants(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_invalide'),
        ]);
        $this->assertGuest();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. ALLUME : un vrai jeton passe, et les gardes existantes tiennent
    // ───────────────────────────────────────────────────────────────────────

    public function test_captcha_login_allume_un_jeton_valide_laisse_passer_la_connexion_globale(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(true);

        $reponse = $this->post(route('login'), $this->identifiants(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->utilisateur);
    }

    public function test_captcha_login_allume_un_jeton_valide_laisse_passer_la_connexion_organization(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(true);

        $reponse = $this->post(
            route('organization.login', $this->organisationPublique),
            $this->identifiantsSansOrganization(['cf-turnstile-response' => self::JETON_FACTICE]),
        );

        $reponse->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->utilisateur);
    }

    public function test_captcha_login_allume_le_formulaire_porte_le_widget_et_la_cle_publique(): void
    {
        $this->allumerLogin();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="turnstile"', $html);
        $debut = strpos($html, 'data-marqueur="turnstile"');
        $region = substr($html, $debut, 900);

        $this->assertStringContainsString('class="cf-turnstile"', $region);
        $this->assertStringContainsString('data-sitekey="'.self::SITEKEY_QUI_PASSE.'"', $region);
        $this->assertStringContainsString('challenges.cloudflare.com/turnstile/v0/api.js', $html);
    }

    public function test_captcha_login_allume_le_formulaire_organization_porte_le_widget(): void
    {
        $this->allumerLogin();

        $html = $this->get(route('organization.login', $this->organisationPublique))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-marqueur="turnstile"', $html);
    }

    public function test_drapeau_login_allume_sans_cle_publique_n_affiche_aucun_widget(): void
    {
        config()->set('services.turnstile.login_enabled', true);
        config()->set('services.turnstile.site_key', '');

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="turnstile"', $html);
    }

    /**
     * Les deux gardes tiennent ENSEMBLE : un captcha valide ne dispense pas
     * du throttling email+IP existant, ni de la resolution Organization
     * (TASK-1728/1749), ni du refus generique.
     */
    public function test_un_captcha_valide_ne_dispense_pas_du_throttling_existant(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(true);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), $this->identifiants([
                'password' => 'mauvais-mot-de-passe',
                'cf-turnstile-response' => self::JETON_FACTICE,
            ]));
        }

        // Le 6e essai est verrouille par le throttle `email|ip` (TASK-1728),
        // avec un jeton VALIDE et un mot de passe CORRECT : la garde existante
        // tranche avant meme que les identifiants ne soient relus.
        $reponse = $this->post(route('login'), $this->identifiants([
            'cf-turnstile-response' => self::JETON_FACTICE,
        ]));

        $reponse->assertSessionHasErrors('email');
        $this->assertNotSame(__('auth.failed'), session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_un_captcha_valide_ne_dispense_pas_de_la_resolution_organization(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(true);

        $reponse = $this->post(route('login'), $this->identifiants([
            'organization' => 'organisation-inconnue-1837',
            'cf-turnstile-response' => self::JETON_FACTICE,
        ]));

        $reponse->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_un_captcha_valide_ne_dispense_pas_du_refus_generique(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(true);

        $reponse = $this->post(route('login'), $this->identifiants([
            'password' => 'mauvais-mot-de-passe',
            'cf-turnstile-response' => self::JETON_FACTICE,
        ]));

        $reponse->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }

    /** Regeneration de session : inchangee, meme avec le captcha allume. */
    public function test_la_session_est_regeneree_apres_une_connexion_captcha_allume(): void
    {
        $this->allumerLogin();
        $this->cloudflareRepond(true);

        $idAvant = $this->app['session']->getId();

        $this->post(route('login'), $this->identifiants(['cf-turnstile-response' => self::JETON_FACTICE]));

        $this->assertNotSame($idAvant, $this->app['session']->getId());
        $this->assertAuthenticatedAs($this->utilisateur);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. Hors scope : l'inscription et l'API restent inchangees
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_captcha_du_login_n_active_pas_celui_de_l_inscription(): void
    {
        $this->allumerLogin();
        config()->set('services.turnstile.enabled', false); // inscription explicitement eteinte

        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="turnstile"', $html);
    }

    public function test_l_api_login_n_a_aucune_regle_turnstile(): void
    {
        $this->allumerLogin();
        Http::fake();

        $reponse = $this->postJson('/api/auth/login', [
            'email' => $this->utilisateur->email,
            'password' => self::MOT_DE_PASSE,
        ]);

        $reponse->assertOk();
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 5. La regle, isolee — meme classe, deux configurations
    // ───────────────────────────────────────────────────────────────────────

    public function test_la_regle_avec_actif_true_ignore_le_drapeau_enabled_de_l_inscription(): void
    {
        config()->set('services.turnstile.enabled', false);
        $this->allumerLogin();
        $this->cloudflareRepond(false, ['invalid-input-response']);

        $validateur = Validator::make(
            ['cf-turnstile-response' => self::JETON_FACTICE],
            ['cf-turnstile-response' => [new TurnstileValide(actif: true)]],
        );

        $this->assertFalse($validateur->passes(), 'La regle a laisse passer alors que actif=true devait la faire tourner.');
    }

    public function test_la_regle_avec_actif_false_ne_fait_aucun_appel_meme_si_enabled_est_vrai(): void
    {
        config()->set('services.turnstile.enabled', true);
        config()->set('services.turnstile.site_key', self::SITEKEY_QUI_PASSE);
        config()->set('services.turnstile.secret_key', self::SECRET_QUI_PASSE);
        Http::fake();

        $validateur = Validator::make(
            ['cf-turnstile-response' => 'nimporte-quoi'],
            ['cf-turnstile-response' => [new TurnstileValide(actif: false)]],
        );

        $this->assertTrue($validateur->passes());
        Http::assertNothingSent();
    }
}
