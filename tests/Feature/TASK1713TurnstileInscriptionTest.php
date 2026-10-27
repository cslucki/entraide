<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Rules\TurnstileValide;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1713 — Cloudflare Turnstile a l'ENTREE de l'inscription.
 *
 * TASK-1712 avait ferme la SORTIE : un nom qui est une publicite ne s'affiche
 * plus. Le compte, lui, etait quand meme cree. Cette TASK ferme l'entree.
 *
 * ## Les trois moities de ce fichier
 *
 * 1. **Le captcha eteint ne change RIEN.** C'est le volet le plus important, et
 *    le moins spectaculaire : 39 POST `/register` vivent dans 8 fichiers de
 *    tests sans aucun rapport avec le captcha. Si ce volet rougit, la
 *    fonctionnalite est inacceptable quelle que soit sa qualite par ailleurs.
 * 2. **Le captcha allume refuse ce qu'il doit refuser** — et, point crucial,
 *    refuse AUSSI quand la verification est impossible (fail-closed).
 * 3. **Le captcha allume laisse passer un vrai jeton.** Une regle qui refuse
 *    tout passerait les deux premiers volets sans rien proteger de reel.
 *
 * ## Zero reseau, et pourquoi les cles de test officielles quand meme
 *
 * `Tests\TestCase::setUp()` appelle `Http::preventStrayRequests()` : aucune
 * requete non doublee ne sort, jamais. Les gardes ci-dessous doublent donc
 * Cloudflare par `Http::fake()`.
 *
 * Les cles posees restent neanmoins les cles GENERIQUES OFFICIELLES de
 * Cloudflare, publiques et documentees. Deux raisons : elles disent au lecteur
 * quel comportement est simule (« 1x… » passe toujours, « 2x… » echoue
 * toujours), et elles permettent de rejouer le meme scenario a la main contre
 * le vrai Siteverify sans rien reecrire. Ce ne sont pas des secrets, et aucune
 * vraie cle n'apparait ici.
 */
class TASK1713TurnstileInscriptionTest extends TestCase
{
    use RefreshDatabase;

    /** Cles generiques publiques de Cloudflare — jamais les vraies. */
    private const SITEKEY_QUI_PASSE = '1x00000000000000000000AA';

    private const SECRET_QUI_PASSE = '1x0000000000000000000000000000000AA';

    private const SECRET_QUI_ECHOUE = '2x0000000000000000000000000000000AA';

    private const JETON_FACTICE = 'XXXX.DUMMY.TOKEN.XXXX';

    private const URL_SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private Organization $organisation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1713',
            'is_active' => true,
        ]);
        app()->instance('current_organization', $this->organisation);
    }

    private function allumer(string $secret = self::SECRET_QUI_PASSE): void
    {
        config()->set('services.turnstile.enabled', true);
        config()->set('services.turnstile.site_key', self::SITEKEY_QUI_PASSE);
        config()->set('services.turnstile.secret_key', $secret);
    }

    private function eteindre(): void
    {
        config()->set('services.turnstile.enabled', false);
    }

    /** @param array<string, mixed> $surcharges */
    private function inscription(array $surcharges = []): array
    {
        return array_merge([
            'first_name' => 'Camille',
            'name' => 'Dubois',
            'email' => 'camille-1713@example.test',
            'phone' => '0601020304',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ], $surcharges);
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
    // 1. ETEINT : rien ne change
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Le volet qui protege les 39 POST `/register` existants.
     *
     * `Http::preventStrayRequests()` fait ici un double office : si le code
     * appelait Cloudflare alors que le captcha est eteint, ce test jetterait
     * une StrayRequestException au lieu de passer.
     */
    public function test_captcha_eteint_l_inscription_fonctionne_sans_jeton(): void
    {
        $this->eteindre();

        $reponse = $this->post('/register', $this->inscription());

        $reponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'camille-1713@example.test']);
    }

    public function test_captcha_eteint_le_formulaire_ne_porte_aucun_widget(): void
    {
        $this->eteindre();

        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="turnstile"', $html);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $html);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. ALLUME : ce qui doit etre refuse l'est
    // ───────────────────────────────────────────────────────────────────────

    public function test_captcha_allume_un_jeton_absent_refuse_l_inscription(): void
    {
        $this->allumer();
        Http::fake();

        $reponse = $this->post('/register', $this->inscription());

        $reponse->assertSessionHasErrors('cf-turnstile-response');
        $this->assertDatabaseMissing('users', ['email' => 'camille-1713@example.test']);

        // Un jeton absent se refuse SANS appeler Cloudflare : `required` est
        // implicite et tranche avant la regle. C'est la bonne economie, et
        // c'est mesure ici plutot qu'affirme.
        Http::assertNothingSent();
    }

    public function test_captcha_allume_un_jeton_refuse_par_cloudflare_refuse_l_inscription(): void
    {
        $this->allumer(self::SECRET_QUI_ECHOUE);
        $this->cloudflareRepond(false, ['invalid-input-response']);

        $reponse = $this->post('/register', $this->inscription(['cf-turnstile-response' => 'jeton-bidon']));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_invalide'),
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'camille-1713@example.test']);
    }

    /**
     * Fail-closed : Cloudflare injoignable REFUSE l'inscription.
     *
     * Le message est l'autre moitie de la garde. Dire « la verification a
     * echoue, refaites le test » pendant une panne de tiers enverrait quelqu'un
     * rejouer un defi qui ne peut pas aboutir, et lui ferait croire a une faute
     * de sa part.
     */
    public function test_captcha_allume_cloudflare_injoignable_refuse_l_inscription(): void
    {
        $this->allumer();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $reponse = $this->post('/register', $this->inscription(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_indisponible'),
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'camille-1713@example.test']);
    }

    /**
     * Drapeau allume, secret manquant : on REFUSE, on ne se desactive pas.
     *
     * Lecon de TASK-1652 — une garde conditionnee a une configuration CONNUE ne
     * garde rien. Si `enabled()` rendait `drapeau && cles presentes`, une PROD
     * mal configuree croirait proteger son inscription et l'aurait grande
     * ouverte, sans un seul signal.
     */
    public function test_drapeau_allume_sans_cle_secrete_refuse_et_ne_se_desactive_pas(): void
    {
        config()->set('services.turnstile.enabled', true);
        config()->set('services.turnstile.site_key', self::SITEKEY_QUI_PASSE);
        config()->set('services.turnstile.secret_key', null);
        Http::fake();

        $this->assertTrue(app(TurnstileVerifier::class)->enabled(), 'Le drapeau s\'est desactive tout seul.');

        $reponse = $this->post('/register', $this->inscription(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasErrors([
            'cf-turnstile-response' => __('validation.turnstile_indisponible'),
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'camille-1713@example.test']);
        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. ALLUME : un vrai jeton passe
    // ───────────────────────────────────────────────────────────────────────

    public function test_captcha_allume_un_jeton_valide_laisse_creer_le_compte(): void
    {
        $this->allumer();
        $this->cloudflareRepond(true);

        $reponse = $this->post('/register', $this->inscription(['cf-turnstile-response' => self::JETON_FACTICE]));

        $reponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'camille-1713@example.test']);
    }

    public function test_captcha_allume_le_formulaire_porte_le_widget_et_la_cle_publique(): void
    {
        $this->allumer();

        $html = $this->get('/register')->assertOk()->getContent();

        // Mesurer la REGION, jamais la page : 9 Ko de HTML contiennent assez de
        // texte pour rendre vraie n'importe quelle assertion (lecon T1697).
        $this->assertStringContainsString('data-marqueur="turnstile"', $html);
        $debut = strpos($html, 'data-marqueur="turnstile"');
        $region = substr($html, $debut, 900);

        $this->assertStringContainsString('class="cf-turnstile"', $region);
        $this->assertStringContainsString('data-sitekey="'.self::SITEKEY_QUI_PASSE.'"', $region);
        $this->assertStringContainsString('challenges.cloudflare.com/turnstile/v0/api.js', $html);
    }

    /**
     * Drapeau allume sans cle PUBLIQUE : aucun widget, mais le serveur refuse
     * quand meme. Un `data-sitekey=""` afficherait un defi impossible a
     * resoudre ; ne rien afficher ne doit pas pour autant ouvrir la porte.
     */
    public function test_drapeau_allume_sans_cle_publique_n_affiche_aucun_widget(): void
    {
        config()->set('services.turnstile.enabled', true);
        config()->set('services.turnstile.site_key', '');

        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="turnstile"', $html);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. T1713 n'a RIEN enleve a T1712
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Les deux gardes tiennent ENSEMBLE, et c'est le point.
     *
     * Un robot qui resoudrait le defi reste refuse par la regle de nom. La
     * mesure serait vide si elle ne prouvait pas d'abord que le captcha, lui,
     * a bien ete accepte : l'erreur porte sur `name`, PAS sur le captcha.
     */
    public function test_un_captcha_valide_ne_dispense_pas_des_regles_de_nom_de_t1712(): void
    {
        $this->allumer();
        $this->cloudflareRepond(true);

        $reponse = $this->post('/register', $this->inscription([
            'cf-turnstile-response' => self::JETON_FACTICE,
            'name' => '+2.84567197 BTC. GET ->>>  graph.org/bsjTOXAD-09-01',
            'email' => 'spam-1713@example.test',
        ]));

        $reponse->assertSessionHasErrors('name');
        $reponse->assertSessionDoesntHaveErrors('cf-turnstile-response');
        $this->assertDatabaseMissing('users', ['email' => 'spam-1713@example.test']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 5. Le contrat d'appel a Cloudflare
    // ───────────────────────────────────────────────────────────────────────

    /**
     * `remoteip` n'est PAS envoye, et c'est une decision, pas un oubli.
     *
     * L'envoyer imposerait que l'IP vue par Laravel soit exactement celle qui a
     * resolu le defi. En local le navigateur est sur l'hote et le serveur dans
     * WSL derriere nginx ; en PROD `config/trustedproxy.php` vaut `null` et
     * Laravel Cloud est auto-detecte. Une mauvaise valeur produirait des refus
     * d'inscriptions LEGITIMES indiagnosticables.
     */
    public function test_l_appel_a_cloudflare_porte_le_secret_et_le_jeton_mais_pas_l_ip(): void
    {
        $this->allumer();
        $this->cloudflareRepond(true);

        app(TurnstileVerifier::class)->verify(self::JETON_FACTICE);

        Http::assertSent(function (Request $requete) {
            $corps = $requete->data();

            return $requete->url() === self::URL_SITEVERIFY
                && $requete->method() === 'POST'
                && ($corps['secret'] ?? null) === self::SECRET_QUI_PASSE
                && ($corps['response'] ?? null) === self::JETON_FACTICE
                && ! array_key_exists('remoteip', $corps);
        });
    }

    /**
     * `success: false` SANS `error-codes` ne doit pas ressembler a un succes.
     *
     * Le verificateur rend une LISTE de motifs, et la liste vide signifie
     * « valide ». Recopier betement un `error-codes` absent rendrait `[]`, donc
     * « valide », sur une reponse qui dit explicitement le contraire. Sabotage
     * le plus silencieux de tout ce lot.
     */
    public function test_un_refus_sans_code_d_erreur_reste_un_refus(): void
    {
        $this->allumer();
        Http::fake([self::URL_SITEVERIFY => Http::response(['success' => false], 200)]);

        $motifs = app(TurnstileVerifier::class)->verify(self::JETON_FACTICE);

        $this->assertNotSame([], $motifs, 'Un refus sans code est passe pour un succes.');
        $this->assertSame(['invalid-input-response'], $motifs);
    }

    /**
     * Descripteurs INERTES : un fournisseur PHPUnit est statique et s'execute
     * AVANT que l'application n'existe. `Http::response()` y resoudrait une
     * facade non amorcee — la reponse se construit donc dans le test.
     *
     * @return array<string, array{mixed, int}>
     */
    public static function reponsesIndisponibles(): array
    {
        return [
            'HTTP 500' => ['boom', 500],
            'HTTP 403' => ['', 403],
            'corps illisible' => ['pas du json', 200],
            'json sans cle success' => [['autre' => true], 200],
        ];
    }

    #[DataProvider('reponsesIndisponibles')]
    public function test_une_reponse_inexploitable_est_traitee_comme_une_panne(mixed $corps, int $statut): void
    {
        $this->allumer();
        Http::fake([self::URL_SITEVERIFY => Http::response($corps, $statut)]);

        $motifs = app(TurnstileVerifier::class)->verify(self::JETON_FACTICE);

        $this->assertSame(['verification-unavailable'], $motifs);
    }

    public function test_un_jeton_vide_est_refuse_sans_appeler_cloudflare(): void
    {
        $this->allumer();
        Http::fake();

        $this->assertSame(['missing-input-response'], app(TurnstileVerifier::class)->verify(''));
        $this->assertSame(['missing-input-response'], app(TurnstileVerifier::class)->verify(null));
        $this->assertSame(['missing-input-response'], app(TurnstileVerifier::class)->verify('   '));

        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 6. La regle, isolee
    // ───────────────────────────────────────────────────────────────────────

    public function test_la_regle_ne_fait_aucun_appel_quand_le_captcha_est_eteint(): void
    {
        $this->eteindre();
        Http::fake();

        $validateur = Validator::make(
            ['cf-turnstile-response' => 'nimporte-quoi'],
            ['cf-turnstile-response' => [new TurnstileValide]],
        );

        $this->assertTrue($validateur->passes());
        Http::assertNothingSent();
    }

    /** @return list<string> les messages rendus par la regle, seule */
    private function messagesDeLaRegle(): array
    {
        return Validator::make(
            ['cf-turnstile-response' => self::JETON_FACTICE],
            ['cf-turnstile-response' => [new TurnstileValide]],
        )->errors()->get('cf-turnstile-response');
    }

    /**
     * Les deux messages sont mesures dans DEUX tests, et c'est une correction.
     *
     * Ils tenaient d'abord dans un seul, avec un seul `Http::fake()` par
     * moitie. Le second ne remplacait pas le premier : la fabrique Laravel
     * FUSIONNE les doublures et rend la PREMIERE qui apparie l'URL. La moitie
     * « panne » recevait donc encore la reponse de refus — et le test etait
     * rouge pour une raison qui n'avait rien a voir avec le code mesure.
     */
    public function test_la_regle_rend_le_message_de_REFUS_quand_cloudflare_refuse(): void
    {
        $this->allumer();
        $this->cloudflareRepond(false, ['invalid-input-response']);

        $this->assertSame([__('validation.turnstile_invalide')], $this->messagesDeLaRegle());
    }

    public function test_la_regle_rend_le_message_de_PANNE_quand_cloudflare_est_en_vrac(): void
    {
        $this->allumer();
        Http::fake([self::URL_SITEVERIFY => Http::response('boom', 500)]);

        $this->assertSame([__('validation.turnstile_indisponible')], $this->messagesDeLaRegle());
    }

    /**
     * Les codes de secret mal configure menent au message de PANNE, pas a celui
     * du refus : ce n'est pas la faute de qui remplit le formulaire, et lui
     * demander de rejouer le defi ne produirait jamais rien.
     */
    public function test_un_secret_invalide_mene_au_message_de_panne(): void
    {
        $this->allumer();
        $this->cloudflareRepond(false, ['invalid-input-secret']);

        $this->assertSame([__('validation.turnstile_indisponible')], $this->messagesDeLaRegle());
    }

    // ───────────────────────────────────────────────────────────────────────
    // 7. Aucun secret ne fuit
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Ni la cle secrete ni le jeton ne doivent atteindre le HTML.
     *
     * La cle PUBLIQUE, elle, y est par construction — c'est sa raison d'etre.
     */
    public function test_la_cle_secrete_n_atteint_jamais_le_html(): void
    {
        $this->allumer();

        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SECRET_QUI_PASSE, $html);
        $this->assertStringContainsString(self::SITEKEY_QUI_PASSE, $html);
    }
}
