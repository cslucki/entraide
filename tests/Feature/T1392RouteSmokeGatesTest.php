<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\Community;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T139.2 — Route Smoke Gates
 *
 * Smoke tests obligatoires pour les routes critiques avant toute
 * migration Community → Organization.
 *
 * Ces tests vérifient le comportement ACTUEL (legacy compatible).
 * Ils ne spécifient PAS le comportement final Organization-only.
 *
 * Catégories :
 *   - Routes publiques root-level (/explorer, /membres, /blog, /boucles, /echanges)
 *   - Routes admin (/admin/dashboard, /admin/users, /admin/services, /admin/requests, /admin/messages)
 *   - Routes community-prefixed (/{community}/explorer, /{community}/dashboard)
 *   - Routes authentifiées (profile, services, requests)
 */
class T1392RouteSmokeGatesTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->community = Organization::factory()->create(['is_active' => true, 'is_public' => true]);
        ResolveUrlOrganization::$defaultOrganizationId = $this->community->id;

        $this->user = User::factory()->create([
            'organization_id' => $this->community->id,
            'organization_id' => $this->community->id,
        ]);
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->community->id,
            'organization_id' => $this->community->id,
        ]);
    }

    protected function tearDown(): void
    {
        ResolveUrlOrganization::$defaultOrganizationId = null;
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Routes publiques root-level
    // ─────────────────────────────────────────────────────────────

    public function test_home_returns_200(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_explorer_returns_200(): void
    {
        $response = $this->get('/explorer');
        $response->assertOk();
    }

    public function test_membres_returns_200(): void
    {
        $response = $this->get('/membres');
        $response->assertOk();
    }

    public function test_echanges_returns_200(): void
    {
        $response = $this->get('/echanges');
        $response->assertOk();
    }

    public function test_boucles_returns_200(): void
    {
        $response = $this->get('/boucles');
        $response->assertOk();
    }

    public function test_blog_index_returns_200(): void
    {
        $response = $this->get('/blog');
        $response->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // Routes root-level avec résolution Organization
    // ─────────────────────────────────────────────────────────────

    public function test_explorer_resolves_organization(): void
    {
        $this->get('/explorer')
            ->assertOk();

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertEquals($this->community->id, app('current_organization')->id);
    }

    public function test_membres_resolves_organization(): void
    {
        $this->get('/membres')
            ->assertOk();

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertEquals($this->community->id, app('current_organization')->id);
    }

    // ─────────────────────────────────────────────────────────────
    // Routes admin (non Organization-scopées)
    // ─────────────────────────────────────────────────────────────

    public function test_admin_dashboard_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_admin_dashboard_redirects_guest(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_dashboard_returns_403_for_non_admin(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_users_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users'))
            ->assertOk();
    }

    public function test_admin_users_redirects_guest(): void
    {
        $this->get(route('admin.users'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_users_returns_403_for_non_admin(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.users'))
            ->assertForbidden();
    }

    public function test_admin_services_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.services'))
            ->assertOk();
    }

    public function test_admin_requests_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.requests'))
            ->assertOk();
    }

    public function test_admin_messages_returns_200_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.messages'))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // Routes communautaires préfixées /{community}/
    // ─────────────────────────────────────────────────────────────

    public function test_community_home_returns_200(): void
    {
        $this->get("/{$this->community->slug}/")
            ->assertOk();
    }

    public function test_community_home_redirects_without_trailing_slash(): void
    {
        $response = $this->get("/{$this->community->slug}");
        $this->assertContains($response->status(), [200, 302]);
    }

    public function test_community_explorer_returns_200(): void
    {
        $this->get("/{$this->community->slug}/explorer")
            ->assertOk();
    }

    public function test_community_membres_returns_200(): void
    {
        $this->get("/{$this->community->slug}/membres")
            ->assertOk();
    }

    public function test_community_dashboard_redirects_guest(): void
    {
        $this->get("/{$this->community->slug}/dashboard")
            ->assertRedirect(route('login'));
    }

    public function test_community_dashboard_returns_200_for_authenticated_user(): void
    {
        $this->actingAs($this->user)
            ->get("/{$this->community->slug}/dashboard")
            ->assertOk();
    }

    public function test_community_echanges_returns_200(): void
    {
        $this->get("/{$this->community->slug}/echanges")
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // Routes authentifiées (profil, paramètres)
    // ─────────────────────────────────────────────────────────────

    public function test_community_profile_edit_returns_200(): void
    {
        $this->actingAs($this->user)
            ->get("/{$this->community->slug}/profile/edit")
            ->assertOk();
    }

    public function test_community_loops_index_returns_200(): void
    {
        $this->actingAs($this->user)
            ->get("/{$this->community->slug}/loops")
            ->assertOk();
    }

    public function test_community_points_index_returns_200(): void
    {
        $this->actingAs($this->user)
            ->get("/{$this->community->slug}/points")
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // Routes nommées — vérification qu'elles existent
    // ─────────────────────────────────────────────────────────────

    public function test_critical_named_routes_exist(): void
    {
        $this->assertNotNull(route('home'));
        $this->assertNotNull(route('explorer'));
        $this->assertNotNull(route('members.index'));
        $this->assertNotNull(route('exchanges.index'));
        $this->assertNotNull(route('boucles.index'));
        $this->assertNotNull(route('blog.index'));
        $this->assertNotNull(route('admin.dashboard'));
        $this->assertNotNull(route('admin.users'));
        $this->assertNotNull(route('admin.services'));
        $this->assertNotNull(route('admin.requests'));
        $this->assertNotNull(route('admin.messages'));
    }

    public function test_community_named_routes_exist(): void
    {
        $this->assertNotNull(route('community.home', ['community' => 'test-org']));
        $this->assertNotNull(route('community.dashboard', ['community' => 'test-org']));
        $this->assertNotNull(route('community.explorer', ['community' => 'test-org']));
        $this->assertNotNull(route('community.members.index', ['community' => 'test-org']));
        $this->assertNotNull(route('community.loops.index', ['community' => 'test-org']));
        $this->assertNotNull(route('community.profile.edit', ['community' => 'test-org']));
    }

    // ─────────────────────────────────────────────────────────────
    // Routes /org/{organization} — smoke tests parallèles
    // ─────────────────────────────────────────────────────────────

    public function test_organization_home_returns_200(): void
    {
        $this->get("/org/{$this->community->slug}/")->assertOk();
    }

    public function test_organization_explorer_returns_200(): void
    {
        $this->get("/org/{$this->community->slug}/explorer")->assertOk();
    }

    public function test_organization_membres_returns_200(): void
    {
        $this->get("/org/{$this->community->slug}/membres")->assertOk();
    }

    public function test_organization_echanges_returns_200(): void
    {
        $this->get("/org/{$this->community->slug}/echanges")->assertOk();
    }

    public function test_organization_dashboard_returns_200_for_authenticated_user(): void
    {
        $this->actingAs($this->user)
            ->get("/org/{$this->community->slug}/dashboard")
            ->assertOk();
    }

    public function test_organization_named_routes_exist(): void
    {
        $this->assertNotNull(route('organization.home', ['organization' => 'test-org']));
        $this->assertNotNull(route('organization.dashboard', ['organization' => 'test-org']));
        $this->assertNotNull(route('organization.explorer', ['organization' => 'test-org']));
        $this->assertNotNull(route('organization.members.index', ['organization' => 'test-org']));
        $this->assertNotNull(route('organization.loops.index', ['organization' => 'test-org']));
        $this->assertNotNull(route('organization.profile.edit', ['organization' => 'test-org']));
    }
}
