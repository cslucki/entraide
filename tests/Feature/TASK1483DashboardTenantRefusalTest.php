<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1483 (P1 tenant) — le tableau de bord d'une Organization refuse un
 * membre etranger, et le lui DIT.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Sessions HTTP reelles sur le banc (curl, cookie jar, login CSRF) :
 *
 * | Acteur | URL | Statut |
 * |---|---|---|
 * | membre de `launchpals` | `/org/launchpals/dashboard` | 200 — attendu |
 * | membre de `launchpals` | `/org/main/dashboard` | **200** |
 * | OrgAdmin de `launchpals` | `/org/main/dashboard` | **200** |
 * | OrgAdmin de `artscilab-en` (privee) | `/org/main/dashboard` | **200** |
 * | membre de `launchpals` | `/org/artscilab-en/dashboard` (**privee**) | **200** |
 *
 * Chaine complete : `web | ResolveOrganization | Authenticate`. Aucune garde
 * d'appartenance.
 *
 * ## Ce qui fuyait : rien. Et c'est ce qui rendait le defaut difficile a voir.
 *
 * 55 occurrences du slug etranger dans la page, toutes des URL de navigation
 * construites depuis le slug que le visiteur venait de taper. Aucun nom
 * d'Organization dans le texte visible, aucun membre, aucune donnee du tenant
 * vise. `DashboardController@index` ne lit que `auth()->user()`.
 *
 * Le defaut n'est donc pas une fuite de donnees : c'est que la page servait au
 * visiteur SES PROPRES donnees **sous l'identite d'un tenant dont il n'est pas
 * membre** — son theme, son logo, son nom de marque, son `header_javascript` —
 * en lui laissant croire qu'il avait sa place ici, et en lui offrant une
 * navigation entiere vers des pages qui, elles, allaient le refuser.
 *
 * ## Pourquoi 403 explique, et pas 404
 *
 * TASK-1479 refuse en 404 sur l'annuaire, et pour une bonne raison : un 403 y
 * confirmerait a un tiers que cette Organization existe. Ici la personne est
 * DEJA connectee et a tape ce slug elle-meme ; un 404 generique lui ferait
 * croire a une page cassee. La regle d'appartenance ne bouge pas d'un mot —
 * seule la maniere de refuser change, via le mode `explain` du middleware qui
 * porte deja cette regle.
 *
 * ## Le nom du tenant est une donnee, pas une decoration
 *
 * Il n'apparait que si l'Organization est `is_public`. Le nommer a un etranger
 * quand elle est privee confirmerait son existence — exactement ce que le 404
 * de TASK-1479 evite. La section D existe pour rougir si cette distinction
 * disparait.
 */
class TASK1483DashboardTenantRefusalTest extends TestCase
{
    use RefreshDatabase;

    /** La FAMILLE complete. Fermer l'index en laissant les quatre autres ouvertes serait un demi-correctif. */
    private const DASHBOARD_ROUTES = [
        'organization.dashboard',
        'organization.dashboard.requests',
        'organization.dashboard.services',
    ];

    private Organization $host;

    private Organization $privateHost;

    private Organization $visitorOrg;

    private User $stranger;

    private User $hostMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-1483-hote',
            'name' => 'Org 1483 Hote',
        ]);

        $this->privateHost = Organization::factory()->create([
            'is_active' => true,
            'is_public' => false,
            'slug' => 'org-1483-privee',
            'name' => 'Confrerie Confidentielle 1483',
        ]);

        $this->visitorOrg = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-1483-visiteur',
            'name' => 'Org 1483 Visiteur',
        ]);

        $this->hostMember = User::factory()->complete()->create(['organization_id' => $this->host->id]);
        $this->stranger = User::factory()->complete()->create(['organization_id' => $this->visitorOrg->id]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le membre garde son tableau de bord — le correctif ne coute rien
    // =====================================================================

    public function test_a_member_still_reaches_the_dashboard_of_their_own_organization(): void
    {
        foreach (self::DASHBOARD_ROUTES as $name) {
            $this->actingAs($this->hostMember)
                ->get(route($name, ['organization' => $this->host->slug]))
                ->assertOk();
        }
    }

    /** Et il y voit toujours ses propres donnees : aucun champ n'a ete masque. */
    public function test_the_member_still_sees_their_own_content(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->hostMember->id,
            'organization_id' => $this->host->id,
            'status' => 'active',
            'title' => 'Reparation de velos anciens 1483',
        ]);

        $this->actingAs($this->hostMember)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertOk()
            ->assertSee($service->title, false);
    }

    /** La forme NON prefixee n'a pas de tenant dans l'URL : elle n'a rien a refuser. */
    public function test_the_unprefixed_dashboard_is_untouched(): void
    {
        $this->actingAs($this->hostMember)->get(route('dashboard'))->assertOk();
    }

    // =====================================================================
    // B. L'etranger : 403, ni 200 ni 404
    // =====================================================================

    public function test_a_member_of_another_organization_is_refused_on_the_whole_dashboard_family(): void
    {
        foreach (self::DASHBOARD_ROUTES as $name) {
            $this->actingAs($this->stranger)
                ->get(route($name, ['organization' => $this->host->slug]))
                ->assertStatus(403);
        }
    }

    /**
     * Le point de la TASK : ce n'est PAS un 404. Une assertion sur 403 seule
     * resterait verte si quelqu'un remplacait la page par un `abort(404)` —
     * elle rougirait, mais sans dire pourquoi. Celle-ci nomme l'exigence.
     */
    public function test_the_refusal_is_never_a_generic_404(): void
    {
        $response = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]));

        $this->assertNotSame(404, $response->getStatusCode(), 'un 404 generique laisserait croire a une page cassee');
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString(__('errors.404_message'), $response->getContent());
    }

    /**
     * Un OrgAdmin d'une autre Organization n'est pas plus membre qu'un autre.
     * Mesure faite : « OrgAdmin » n'est pas un attribut de `users`, c'est
     * `Organization->admin_id` — le test construit donc le vrai lien, pas une
     * colonne imaginee.
     */
    public function test_an_org_admin_of_another_organization_is_refused_too(): void
    {
        $foreignOrgAdmin = User::factory()->complete()->create(['organization_id' => $this->visitorOrg->id]);
        $this->visitorOrg->forceFill(['admin_id' => $foreignOrgAdmin->id])->save();

        $this->actingAs($foreignOrgAdmin)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertStatus(403);
    }

    // =====================================================================
    // C. Le refus EXPLIQUE — les mots exacts, et les deux CTA
    // =====================================================================

    public function test_the_refusal_says_what_happened_and_offers_a_way_back(): void
    {
        $html = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertStatus(403)
            ->getContent();

        // « Cette page est reservee aux membres de {organization}. »
        $this->assertStringContainsString(
            e(__('errors.org_member_required_heading', ['organization' => $this->host->name])),
            $html
        );

        // « Vous etes connecte avec un compte qui n'appartient pas a cette organisation. »
        $this->assertStringContainsString(e(__('errors.org_member_required_body')), $html);

        // CTA principal : vers SON espace, pas vers une destination inventee.
        $this->assertStringContainsString(e(__('errors.org_member_required_own_space')), $html);
        $this->assertStringContainsString(
            e(route('organization.dashboard', ['organization' => $this->visitorOrg->slug])),
            $html,
            'le CTA principal doit pointer vers le tableau de bord de SON Organization'
        );
    }

    /**
     * Le CTA secondaire n'est pas decoratif : il n'existe que si cet accueil
     * existe. Il est propose ici parce que `organization.home` est declaree et
     * que l'Organization est publique.
     */
    public function test_the_secondary_cta_points_at_a_real_public_landing(): void
    {
        $this->assertTrue(Route::has('organization.home'), 'premisse du test');

        $html = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->getContent();

        $this->assertStringContainsString(e(__('errors.org_member_required_public_home')), $html);
        $this->assertStringContainsString(
            e(route('organization.home', ['organization' => $this->host->slug])),
            $html
        );
    }

    /** Le refus reste lisible en anglais : aucune cle ne manque. */
    public function test_the_refusal_is_translated(): void
    {
        $this->stranger->forceFill(['preferred_locale' => 'en'])->save();

        $html = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertStatus(403)
            ->getContent();

        $this->assertStringContainsString('does not belong to this organization', $html);
        $this->assertStringNotContainsString('org_member_required_body', $html, 'cle non traduite rendue telle quelle');
    }

    // =====================================================================
    // D. Rien du tenant vise — et une Organization privee n'est meme pas nommee
    // =====================================================================

    /**
     * Nommer une Organization privee a quelqu'un qui n'en fait pas partie
     * confirmerait son existence. Le texte devient neutre, et le second CTA
     * disparait : il n'y a pas de landing publique a proposer.
     */
    public function test_a_private_organization_is_never_named_to_a_stranger(): void
    {
        $html = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->privateHost->slug]))
            ->assertStatus(403)
            ->getContent();

        $this->assertStringNotContainsString($this->privateHost->name, $html, 'le nom d\'une Organization privee ne doit pas apparaitre');
        $this->assertStringContainsString(e(__('errors.org_member_required_heading_neutral')), $html);
        $this->assertStringNotContainsString(e(__('errors.org_member_required_public_home')), $html);
    }

    /**
     * Le layout du tenant est le layout du tenant REFUSE. Mesure faite, il
     * porte son `header_javascript` en brut, son theme, son logo et son nom de
     * marque — partages dans toute vue par le `View::composer('*')`. Un refus
     * habille des habits du tenant refuse n'est pas un refus.
     */
    public function test_the_refusal_page_carries_nothing_of_the_targeted_tenant(): void
    {
        $this->host->forceFill([
            'header_javascript_enabled' => true,
            'header_javascript' => '<script>window.mouchard1483 = true;</script>',
            'platform_name' => 'Plateforme Hote 1483',
            // `logo_url` est un ACCESSEUR : la colonne est `logo_path`.
            'logo_path' => 'logos/logo-hote-1483.png',
        ])->save();

        $colleague = User::factory()->complete()->create([
            'organization_id' => $this->host->id,
            'name' => 'Ilona Fabbri',
        ]);

        $html = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertStatus(403)
            ->getContent();

        $this->assertStringNotContainsString('mouchard1483', $html, 'le JavaScript du tenant refuse ne doit pas etre servi');
        $this->assertStringNotContainsString('Plateforme Hote 1483', $html);
        $this->assertNotSame('', (string) $this->host->fresh()->logo_url, 'premisse : le logo du tenant est bien resolu');
        $this->assertStringNotContainsString('logo-hote-1483.png', $html);
        $this->assertStringNotContainsString('Ilona Fabbri', $html, 'aucun membre du tenant vise');
        $this->assertStringNotContainsString($colleague->email, $html);
    }

    /**
     * TASK-1145 : monter le Shell sur une page dont l'objet a ete refuse
     * inscrirait son instantane Livewire, dont `memo.path` — l'URL qui porte
     * l'identifiant refuse.
     */
    public function test_the_refusal_page_mounts_no_ai_shell_and_no_side_nav(): void
    {
        $html = $this->actingAs($this->stranger)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->getContent();

        $this->assertStringNotContainsString('wire:snapshot', $html);
        $this->assertStringNotContainsString('data-ai-shell', $html);
        $this->assertStringNotContainsString('data-ai-fab', $html);
    }

    // =====================================================================
    // E. Le seul contournement transverse est celui qui existait deja
    // =====================================================================

    /**
     * `is_admin` est EXACTEMENT le predicat que `OrgAdminMiddleware` utilise
     * deja. On le reprend tel quel : ouvrir un nouveau contournement a
     * l'occasion d'un correctif d'acces serait le contraire du but.
     */
    public function test_the_super_admin_keeps_the_already_authorized_bypass(): void
    {
        $superAdmin = User::factory()->complete()->create([
            'organization_id' => $this->visitorOrg->id,
            'is_admin' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertOk();
    }

    /** Et aucun autre attribut n'ouvre la porte. */
    public function test_no_other_attribute_grants_a_cross_tenant_dashboard(): void
    {
        $decorated = User::factory()->complete()->create([
            'organization_id' => $this->visitorOrg->id,
            'email_verified_at' => now(),
        ]);
        $this->visitorOrg->forceFill(['admin_id' => $decorated->id])->save();

        $this->actingAs($decorated)
            ->get(route('organization.dashboard', ['organization' => $this->host->slug]))
            ->assertStatus(403);
    }

    // =====================================================================
    // F. La garde est bien celle qui refuse — la structure, pas seulement l'effet
    // =====================================================================

    /**
     * Si quelqu'un retire `organization.member:explain` des routes, ce test
     * rougit en nommant la cause, avant meme que les statuts ne changent.
     */
    public function test_every_dashboard_route_declares_the_membership_guard(): void
    {
        foreach (self::DASHBOARD_ROUTES as $name) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === $name);

            $this->assertNotNull($route, "[{$name}] route absente");

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, "[{$name}] doit exiger une session");
            $this->assertContains('organization.member:explain', $middleware, "[{$name}] doit exiger l'appartenance");
        }
    }

    /**
     * Une seule autorite d'APPARTENANCE.
     *
     * A distinguer soigneusement de ce que le controleur fait deja et doit
     * continuer a faire : `$serviceRequest->organization_id !== $organization->id`
     * borne l'OBJET au tenant visite. C'est une garde de ressource, elle est
     * legitime et anterieure. Ce que ce test interdit, c'est une seconde regle
     * sur le VISITEUR — elle divergerait de `EnsureOrganizationMember` au
     * premier changement.
     */
    public function test_the_dashboard_controller_holds_no_second_membership_rule(): void
    {
        $source = php_strip_whitespace(app_path('Http/Controllers/DashboardController.php'));

        foreach (['$user->organization_id !==', 'auth()->user()->organization_id !==', 'assertUserBelongsToOrganization'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "seconde regle d'appartenance dans le controleur : {$forbidden}");
        }

        // Et la garde de ressource, elle, est toujours la.
        $this->assertStringContainsString('organization_id !==', $source, 'premisse : la garde de ressource existe');
    }
}
