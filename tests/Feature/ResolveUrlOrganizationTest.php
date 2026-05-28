<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ResolveUrlOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private array $savedExact = [];

    private array $savedPrefixes = [];

    private array $savedRoutes = [];

    private array $savedPersonalRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedExact = ResolveUrlOrganization::$platformGlobalExact;
        $this->savedPrefixes = ResolveUrlOrganization::$platformGlobalPrefixes;
        $this->savedRoutes = ResolveUrlOrganization::$defaultOrganizationRoutes;
        $this->savedPersonalRoutes = ResolveUrlOrganization::$authenticatedPersonalRoutes;
    }

    protected function tearDown(): void
    {
        ResolveUrlOrganization::$platformGlobalExact = $this->savedExact;
        ResolveUrlOrganization::$platformGlobalPrefixes = $this->savedPrefixes;
        ResolveUrlOrganization::$defaultOrganizationRoutes = $this->savedRoutes;
        ResolveUrlOrganization::$authenticatedPersonalRoutes = $this->savedPersonalRoutes;
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Platform global — vraies routes existantes (Option A)
    // -----------------------------------------------------------------------

    public function test_root_is_platform_global(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_mentions_legales_is_platform_global(): void
    {
        $response = $this->get('/mentions-legales');

        $response->assertOk();
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_login_is_platform_global(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_register_is_platform_global(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_forgot_password_is_platform_global(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertOk();
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_admin_prefix_does_not_resolve_org(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect(); // needs auth → redirect to login
        $this->assertFalse(app()->bound('current_organization'));
    }

    public function test_partners_prefix_is_platform_global(): void
    {
        $request = Request::create('/partners', 'GET');
        $middleware = new ResolveUrlOrganization;
        $handled = false;

        $middleware->handle($request, function () use (&$handled) {
            $handled = true;

            return response('ok');
        });

        $this->assertTrue($handled);
        $this->assertFalse(app()->bound('current_organization'));
    }

    // -----------------------------------------------------------------------
    // Default Organization — test unitaire direct (Option B)
    // -----------------------------------------------------------------------

    public function test_known_feature_route_resolves_default_org(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $request = Request::create('/explorer', 'GET');
        $middleware = new ResolveUrlOrganization;
        $handled = false;

        $response = $middleware->handle($request, function () use (&$handled) {
            $handled = true;

            return response('ok');
        });

        $this->assertTrue($handled);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(app()->bound('current_organization'));
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    public function test_known_feature_route_with_org_via_real_route(): void
    {
        Organization::factory()->create(['is_active' => true]);

        // Without web group activation (T075.3+), the real /explorer route
        // does NOT run through ResolveUrlOrganization. This test documents
        // that the middleware is NOT live by default.
        $response = $this->get('/explorer');

        $this->assertTrue($response->getStatusCode() < 500);
    }

    public function test_known_feature_route_returns_404_without_org(): void
    {
        $request = Request::create('/explorer', 'GET');
        $middleware = new ResolveUrlOrganization;

        $caught = false;
        try {
            $middleware->handle($request, fn () => response('ok'));
        } catch (NotFoundHttpException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected NotFoundHttpException for known route without org');
    }

    // -----------------------------------------------------------------------
    // Authenticated user resolution — test unitaire direct (Option B)
    // -----------------------------------------------------------------------

    public function test_dashboard_resolves_authenticated_user_org(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->actingAs($user);

        $request = Request::create('/dashboard', 'GET');
        $middleware = new ResolveUrlOrganization;

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    public function test_dashboard_returns_404_when_user_has_no_org(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $this->actingAs($user);

        $request = Request::create('/dashboard', 'GET');
        $middleware = new ResolveUrlOrganization;

        $caught = false;
        try {
            $middleware->handle($request, fn () => response('ok'));
        } catch (NotFoundHttpException $e) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }

    public function test_guest_dashboard_passes_through_without_org_binding(): void
    {
        // /dashboard is an authenticated personal route.
        // A guest must not receive the default org — the auth middleware
        // handles the redirect to login. No org binding, no 404.
        $request = Request::create('/dashboard', 'GET');
        $middleware = new ResolveUrlOrganization;
        $handled = false;

        $response = $middleware->handle($request, function () use (&$handled) {
            $handled = true;

            return response('ok');
        });

        $this->assertTrue($handled);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse(app()->bound('current_organization'));
    }

    // -----------------------------------------------------------------------
    // Unknown route — test unitaire direct (Option B)
    // -----------------------------------------------------------------------

    public function test_unknown_route_passes_through_transparently(): void
    {
        $request = Request::create('/some-unknown-path', 'GET');
        $middleware = new ResolveUrlOrganization;

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse(app()->bound('current_organization'));
    }

    // -----------------------------------------------------------------------
    // Partner slug routes — fail-safe 404
    // -----------------------------------------------------------------------

    public function test_partner_slug_with_known_feature_returns_404(): void
    {
        // /{slug}/{feature} with no partner mapping must never be tenantless.
        // Partner → Organization resolution is a future task (T075.4+).
        $request = Request::create('/bni/blog', 'GET');
        $middleware = new ResolveUrlOrganization;

        $caught = false;
        try {
            $middleware->handle($request, fn () => response('ok'));
        } catch (NotFoundHttpException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Expected 404 for partner slug route without partner mapping');
    }

    // -----------------------------------------------------------------------
    // Community-prefixed routes — skip
    // -----------------------------------------------------------------------

    public function test_skips_community_prefixed_routes(): void
    {
        $request = Request::create('/test-comm/test-org-skip', 'GET');
        $router = app('router');
        $route = $router->get('/{community}/test-org-skip', fn () => 'ok');
        $request->setRouteResolver(function () use ($router, $request) {
            return $router->getRoutes()->match($request);
        });

        $middleware = new ResolveUrlOrganization;
        $handled = false;

        $middleware->handle($request, function () use (&$handled) {
            $handled = true;

            return response('ok');
        });

        $this->assertTrue($handled);
        $this->assertFalse(app()->bound('current_organization'),
            'ResolveUrlOrganization must skip community-prefixed routes');
    }

    // -----------------------------------------------------------------------
    // Already resolved — skip
    // -----------------------------------------------------------------------

    public function test_skips_when_organization_already_resolved(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $org);

        $request = Request::create('/dashboard', 'GET');
        $middleware = new ResolveUrlOrganization;

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    // -----------------------------------------------------------------------
    // Legacy compatibility
    // -----------------------------------------------------------------------

    public function test_binds_current_community_for_legacy(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);

        $request = Request::create('/explorer', 'GET');
        $middleware = new ResolveUrlOrganization;

        $middleware->handle($request, fn () => response('ok'));

        $this->assertTrue(app()->bound('current_community'));
        $this->assertEquals($org->id, app('current_community')->id);
    }

    public function test_does_not_override_existing_current_community(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $existingOrg = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_community', $existingOrg);

        $request = Request::create('/explorer', 'GET');
        $middleware = new ResolveUrlOrganization;

        $middleware->handle($request, fn () => response('ok'));

        $this->assertEquals($existingOrg->id, app('current_community')->id);
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public function test_url_organization_middleware_alias_is_registered(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayHasKey('url.organization', $aliases);
        $this->assertEquals(ResolveUrlOrganization::class, $aliases['url.organization']);
    }

    public function test_resolve_url_organization_is_in_web_group(): void
    {
        $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(ResolveUrlOrganization::class, $webGroup);
    }

    public function test_default_organization_id_static_is_null_by_default(): void
    {
        $this->assertNull(ResolveUrlOrganization::$defaultOrganizationId);
    }
}
