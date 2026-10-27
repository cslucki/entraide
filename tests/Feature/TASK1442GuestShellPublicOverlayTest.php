<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellSurface;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellDisplay;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1442 — SW-8a : le runtime public du Shell Welcome + l'OVERLAY (V3 §19,
 * MASTER Q70). Simple visite = rien ; premier geste = identite + cookie +
 * conversation + reponse + ledger ; Shell indisponible = aucune identite ;
 * refus = honnete, jamais une reponse fabriquee ; Shell membre intact.
 */
class TASK1442GuestShellPublicOverlayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ready;

    private Organization $disabled;

    private Organization $degraded;

    private Organization $private;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
            'ai.guest_shell.visitor_monthly_max_messages' => 30,
            'ai.guest_shell.rate_limit_per_minute' => 6,
        ]);
        $policies = app(GuestShellPolicyService::class);

        $this->ready = Organization::factory()->create(['slug' => 'org-ready-14xx', 'name' => 'Ready Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'Entraide entre freelances']);
        $policies->update($this->ready, ['enabled' => true, 'max_messages' => 5, 'display_mode' => 'overlay']);
        OrganizationAiSetting::create(['organization_id' => $this->ready->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);

        $this->disabled = Organization::factory()->create(['slug' => 'org-off-14xx', 'name' => 'Off Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);

        $this->degraded = Organization::factory()->create(['slug' => 'org-degraded-14xx', 'name' => 'Degraded Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $policies->update($this->degraded, ['enabled' => true, 'max_messages' => 5]);

        $this->private = Organization::factory()->create(['slug' => 'org-private-14xx', 'name' => 'Private Guild', 'is_active' => true, 'is_public' => false, 'locale' => 'fr']);
        $policies->update($this->private, ['enabled' => true]);
    }

    private function home(Organization $organization): string
    {
        return route('organization.home', ['organization' => $organization->slug]);
    }

    private function endpoint(Organization $organization): string
    {
        return route('organization.shell.message', ['organization' => $organization->slug]);
    }

    /** La valeur CHIFFREE du cookie bp_guest posee par une reponse — rejouee telle quelle, comme un navigateur. */
    private function guestCookie(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestVisitorResolver::COOKIE) {
                return (string) $cookie->getValue();
            }
        }

        return null;
    }

    /** La cle BRUTE derriere une valeur chiffree (EncryptCookies + CookieValuePrefix). */
    private function rawKey(string $encrypted): string
    {
        return CookieValuePrefix::remove(app('encrypter')->decrypt($encrypted, false));
    }

    /** Ce qu'un navigateur enverrait pour une cle brute connue. */
    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    /** Les requetes JSON de la suite de tests n'envoient les cookies qu'avec withCredentials(). */
    private function asBrowser(string $encryptedCookie): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $encryptedCookie);
    }

    // NB : les attributs data-guest-shell-* figurent aussi dans le script inline du widget ; les assertions visent l'ELEMENT rendu (`attr>`).
    private function assertNothingCreated(string $why): void
    {
        $this->assertSame(0, GuestVisitor::count(), $why);
        $this->assertSame(0, GuestConversation::count(), $why);
        $this->assertSame(0, GuestMessage::count(), $why);
        $this->assertSame(0, AiProviderInvocation::count(), $why);
    }

    // ── 1. La simple visite ────────────────────────────────────────────────

    // ── 1. La simple visite ────────────────────────────────────────────────

    /**
     * CHANGEMENT D'INTENTION PRODUIT — TASK-1692 / MICRO-11.7.
     *
     * Cette methode s'appelait `..._mounts_the_overlay_only_when_the_shell_is_available`
     * et verifiait que les trois gabarits d'accueil MONTAIENT l'overlay invite.
     * MASTER a tranche : aucun overlay Shell invite sur les landings publiques.
     *
     * Elle prouve donc desormais l'ABSENCE, et elle la prouve sur les deux
     * acteurs, pour deux raisons differentes et independantes :
     *
     *   * l'ANONYME — `OrganizationLandingController:35` :
     *     `$guestShell = $showInternalHomepageContent && …`, et
     *     `$showInternalHomepageContent` vaut `false` sans acteur ;
     *   * le MEMBRE — `GuestShellSurface:154` : la surface se declare
     *     `authenticated` et invisible, regle de TASK-1442 anterieure a
     *     TASK-1692.
     *
     * Ce qui n'est PAS juge ici, volontairement : le contenu marketing des
     * landings, leurs metadonnees sociales et leurs liens publics. Ils ne
     * dependent pas du Shell, et les melanger a cette mesure rendrait le rouge
     * illisible le jour ou l'un d'eux bougera.
     */
    public function test_a_simple_visit_mounts_no_guest_shell_overlay_on_any_landing(): void
    {
        GuestShellAgent::fake([]);

        // 1. L'etat de la politique reste distinguable LA OU IL FAIT AUTORITE :
        //    dans le payload serveur. Mesure faite AVANT toute authentification
        //    — `request()` porterait sinon l'acteur, et la surface repondrait
        //    `authenticated` au lieu de la vraie raison.
        $surface = app(GuestShellSurface::class);

        $this->assertSame(
            GuestShellDisplay::REASON_POLICY_NOT_READY,
            $surface->read($this->degraded, request())['display']['reason'],
            'la raison degradee reste lisible cote serveur',
        );
        $this->assertSame(
            GuestShellDisplay::REASON_DISABLED,
            $surface->read($this->disabled, request())['display']['reason'],
            'et la raison desactivee aussi',
        );

        // 2. ANONYME — les TROIS gabarits d'accueil, et quel que soit l'etat de
        //    la politique : aucune trace du Shell invite. Les landings restent
        //    publiques (200) : c'est l'overlay qui part, pas la page.
        foreach ([null, 'bouclepro_hero_v2', 'artscilab_hero'] as $gabarit) {
            if ($gabarit !== null) {
                $this->ready->update(['homepage_template' => $gabarit]);
            }

            $html = $this->get($this->home($this->ready))
                ->assertOk()
                ->assertCookieMissing(GuestVisitorResolver::COOKIE)
                ->getContent();

            $this->assertStringNotContainsString('data-guest-shell', $html,
                'overlay Shell invite rendu a un anonyme sur le gabarit ['.($gabarit ?? 'defaut').']');
            $this->assertStringNotContainsString('bpgs-msg', $html,
                'bulle du Shell invite rendue sur le gabarit ['.($gabarit ?? 'defaut').']');
        }

        foreach ([$this->disabled, $this->degraded] as $organisation) {
            $html = $this->get($this->home($organisation))
                ->assertOk()
                ->assertCookieMissing(GuestVisitorResolver::COOKIE)
                ->getContent();

            $this->assertStringNotContainsString('data-guest-shell', $html,
                'overlay rendu sur ['.$organisation->slug.']');
        }

        // 3. MEMBRE — inactif aussi, et par l'autre porte. Sans ce volet, le
        //    test serait vert sur une page qui aurait simplement cesse de
        //    rendre quoi que ce soit.
        $membre = User::factory()->complete()->create(['organization_id' => $this->ready->id]);

        $htmlMembre = $this->actingAs($membre)->get($this->home($this->ready))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-guest-shell', $htmlMembre,
            'un membre ne doit pas davantage recevoir l\'overlay invite');
        $this->assertNotEmpty($htmlMembre, 'la landing reste servie a un membre');

        // 4. LA CHATLOOP INTERNE EST PRESERVEE — c'est l'autre moitie du
        //    contrat MASTER, et la seule assertion de ce test qui ne portait
        //    deja pas sur l'overlay. Elle lit le layout, pas une reponse HTTP :
        //    elle reste vraie et doit le rester.
        $this->assertStringContainsString(
            '<livewire:ai-shell',
            file_get_contents(resource_path('views/layouts/app.blade.php')),
            'le Shell membre est intact',
        );

        // 5. Et rien n'a ete cree au passage.
        $this->assertNothingCreated('une simple visite ne cree rien');
        GuestShellAgent::assertNeverPrompted();
    }

    // =====================================================================
    // TASK-1692 — LE CONTRAT A REMPLACE LE PARCOURS
    //
    // Les scenarios fonctionnels qui vivaient ici — premier message, reprise,
    // refus de grille, panne de fournisseur — mesuraient un visiteur ANONYME
    // qui converse. Ce parcours n'existe plus, et il n'existe plus pour
    // PERSONNE :
    //
    //   * l'anonyme est arrete par `auth` sur le groupe `/org/{organization}`
    //     (TASK-1692) : 302 en navigation, 401 en JSON ;
    //   * la personne CONNECTEE est arretee par la surface elle-meme —
    //     `GuestShellSurface:154` rend `display.visible = false` et
    //     `reason = authenticated`, et `turn = unavailable`. Ce n'est pas un
    //     effet de bord de TASK-1692 : le controleur le dit depuis TASK-1442,
    //     « ici, jamais un utilisateur ».
    //
    // Il n'existe donc AUCUN acteur capable de faire repondre ce Shell. Les
    // reecrire « avec un membre autorise » aurait produit des tests verts
    // mesurant une surface inerte — pire qu'une absence de test, parce qu'ils
    // auraient eu l'air de couvrir le moteur de conversation.
    //
    // Ce qui les remplace est ce qui RESTE vrai et mesurable : le contrat de
    // refus, pour les trois acteurs, et l'absence totale d'effet metier.
    // =====================================================================

    /**
     * Le contrat complet, acteur par acteur. Les trois cas sont dans le MEME
     * test : separes, chacun serait vert sur une surface muree pour tout le
     * monde, et personne ne verrait la difference.
     */
    public function test_the_shell_endpoints_refuse_every_actor_and_produce_no_business_effect(): void
    {
        $show = route('organization.shell.show', ['organization' => $this->ready->slug]);
        $message = $this->endpoint($this->ready);

        // 1. ANONYME — navigation : redirection vers le login borne.
        $this->get($show)->assertRedirect(route('organization.login', ['organization' => $this->ready->slug]));

        // 2. ANONYME — JSON : 401, en lecture comme en ecriture. C'est 401 et
        //    non 302 parce qu'une requete JSON n'est pas redirigee.
        $this->getJson($show)->assertUnauthorized();
        $this->postJson($message, ['message' => 'Bonjour, que faites-vous ?'])->assertUnauthorized();

        $this->assertNothingCreated('un refus anonyme ne cree rien');
        GuestShellAgent::assertNeverPrompted();

        // 3. MEMBRE LEGITIME — la porte s'ouvre, la surface se tait. Elle rend
        //    200 et se declare indisponible : c'est la regle TASK-1442, pas un
        //    accident. La mesurer ici evite qu'on la croie joignable.
        $membre = User::factory()->complete()->create(['organization_id' => $this->ready->id]);

        $this->actingAs($membre)->getJson($show)
            ->assertOk()
            ->assertJsonPath('display.visible', false)
            ->assertJsonPath('display.reason', GuestShellDisplay::REASON_AUTHENTICATED);

        $this->actingAs($membre->fresh())->postJson($message, ['message' => 'Bonjour'])
            ->assertOk()
            ->assertJsonPath('turn', 'unavailable');

        // Et SURTOUT : rien n'est cree, aucun fournisseur n'est appele. Un
        // membre ne peut donc pas consommer le budget IA invite par cette
        // porte — c'est la protection contre l'abus que ce fichier doit
        // continuer de tenir.
        $this->assertNothingCreated('un membre ne reveille pas la surface invitee');
        GuestShellAgent::assertNeverPrompted();

        // 4. MEMBRE D'UNE AUTRE ORGANIZATION — 404, pas d'oracle.
        $autre = Organization::factory()->create(['slug' => 'org-autre-1442', 'is_active' => true, 'is_public' => true]);
        $etranger = User::factory()->complete()->create(['organization_id' => $autre->id]);

        $this->actingAs($etranger)->getJson($show)->assertNotFound();
        $this->actingAs($etranger->fresh())->postJson($message, ['message' => 'Bonjour'])->assertNotFound();

        $this->assertNothingCreated('un etranger non plus');
        GuestShellAgent::assertNeverPrompted();
    }

    /**
     * La borne qui SURVIT a la fermeture : une Organization non publique ou
     * inactive n'a aucune surface Guest, et le controleur le dit en 404 —
     * avant meme toute question d'appartenance. Mesure faite avec un membre
     * de CETTE Organization, sans quoi la garde de groupe repondrait la
     * premiere et ce test ne mesurerait plus le controleur.
     */
    public function test_a_non_public_organization_still_has_no_guest_surface_at_all(): void
    {
        $membrePrive = User::factory()->complete()->create(['organization_id' => $this->private->id]);

        $this->actingAs($membrePrive)
            ->getJson(route('organization.shell.show', ['organization' => $this->private->slug]))
            ->assertNotFound();

        $this->actingAs($membrePrive->fresh())
            ->postJson($this->endpoint($this->private), ['message' => 'Bonjour'])
            ->assertNotFound();

        $this->assertNothingCreated('une Organization non publique n\'a pas de surface invitee');
        GuestShellAgent::assertNeverPrompted();
    }

    /**
     * Les deux routes portent desormais la garde du groupe, et gardent leur
     * `throttle`. L'ancienne version de ce test assertait `assertNotContains('auth')`
     * — elle defendait la publicite du Shell, que MASTER a retiree.
     *
     * Mesure sur la pile EFFECTIVE (`Router::gatherRouteMiddleware()`) : les
     * alias bruts ignorent `withoutMiddleware()`, et c'est ce defaut qui avait
     * desarme la garde de TASK-1489.
     */
    public function test_the_shell_routes_carry_the_group_guard_and_keep_their_throttle(): void
    {
        foreach (['organization.shell.show', 'organization.shell.message'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "$name a disparu");

            $effective = app('router')->gatherRouteMiddleware($route);

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $effective, "$name : la garde TASK-1692");
            $this->assertContains(\App\Http\Middleware\EnsureOrganizationMember::class, $effective, "$name : l'appartenance");

            $brut = array_map('strval', $route->gatherMiddleware());
            $this->assertNotEmpty(
                array_filter($brut, static fn ($m) => str_starts_with($m, 'throttle:')),
                "$name : le throttle de TASK-1442 reste pose",
            );
        }
    }
}
