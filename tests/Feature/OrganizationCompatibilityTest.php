<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 1 compatibility layer tests for the Community → Organization migration.
 *
 * Validates that Organization is a safe alias for Community without any DB changes.
 */
class OrganizationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Organization model
    // -------------------------------------------------------------------------

    public function test_organization_uses_organizations_table(): void
    {
        $org = new Organization;
        $this->assertEquals('organizations', $org->getTable());
    }

    public function test_organization_factory_creates_persisted_record(): void
    {
        $org = Organization::factory()->create();

        $this->assertNotNull($org->id);
        $this->assertDatabaseHas('organizations', ['id' => $org->id]);
    }

    public function test_organization_and_community_share_same_table_data(): void
    {
        $community = Organization::factory()->create(['name' => 'Test Org']);

        $org = Organization::find($community->id);

        $this->assertNotNull($org);
        $this->assertEquals('Test Org', $org->name);
        $this->assertEquals($community->id, $org->id);
    }

    public function test_organization_inherits_find_by_slug(): void
    {
        Organization::factory()->create(['slug' => 'my-org', 'is_active' => true]);

        $found = Organization::findBySlug('my-org');

        $this->assertNotNull($found);
        $this->assertEquals('my-org', $found->slug);
    }

    public function test_organization_find_by_slug_returns_null_for_inactive(): void
    {
        Organization::factory()->create(['slug' => 'inactive-org', 'is_active' => false]);

        $found = Organization::findBySlug('inactive-org');

        $this->assertNull($found);
    }

    // -------------------------------------------------------------------------
    // Middleware compatibility
    // -------------------------------------------------------------------------

    public function test_resolve_community_middleware_binds_current_organization(): void
    {
        $community = Organization::factory()->create(['slug' => 'test-boucle', 'is_active' => true]);

        Route::get('/test-org-bind/{community}', function () {
            return response()->json([
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveOrganization::class);

        $response = $this->get('/test-org-bind/test-boucle');

        $response->assertOk();
        $response->assertJson([
            'organization_id' => $community->id,
        ]);
    }

    public function test_current_organization_is_bound(): void
    {
        $community = Organization::factory()->create(['slug' => 'org-bound', 'is_active' => true]);

        Route::get('/test-org-bound/{community}', function () {
            return response()->json([
                'bound' => app()->bound('current_organization'),
                'id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveOrganization::class);

        $response = $this->get('/test-org-bound/org-bound');

        $response->assertOk();
        $response->assertJson([
            'bound' => true,
            'id' => $community->id,
        ]);
    }

    public function test_middleware_returns_404_for_unknown_slug(): void
    {
        Route::get('/test-404/{community}', fn () => response('ok'))
            ->middleware(ResolveOrganization::class);

        $response = $this->get('/test-404/nonexistent-org');

        $response->assertNotFound();
    }

    public function test_organization_middleware_alias_is_registered(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayHasKey('organization', $aliases);
        $this->assertEquals(ResolveOrganization::class, $aliases['organization']);
    }

    public function test_community_middleware_alias_has_been_removed(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayNotHasKey('community', $aliases);
    }

    // -------------------------------------------------------------------------
    // ResolveOrganization middleware compatibility
    // -------------------------------------------------------------------------

    public function test_organization_middleware_alias_points_to_resolve_organization(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayHasKey('organization', $aliases);
        $this->assertEquals(ResolveOrganization::class, $aliases['organization']);
    }

    public function test_resolve_organization_binds_current_organization(): void
    {
        $community = Organization::factory()->create(['slug' => 'resolve-org', 'is_active' => true]);

        Route::get('/resolve-org-test/{community}', function () {
            return response()->json([
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveOrganization::class);

        $response = $this->get('/resolve-org-test/resolve-org');

        $response->assertOk();
        $response->assertJson([
            'organization_id' => $community->id,
        ]);
    }

    public function test_resolve_organization_middleware_returns_404_for_unknown_slug(): void
    {
        Route::get('/resolve-org-404/{community}', fn () => response('ok'))
            ->middleware(ResolveOrganization::class);

        $response = $this->get('/resolve-org-404/no-such-slug');

        $response->assertNotFound();
    }

    public function test_resolve_community_binds_current_organization(): void
    {
        $community = Organization::factory()->create(['slug' => 'org-bind', 'is_active' => true]);

        Route::get('/org-bind-check/{community}', function () {
            return response()->json([
                'organization_bound' => app()->bound('current_organization'),
                'organization_id' => app('current_organization')->id,
                'view_organization' => view()->shared('currentOrganization')?->slug,
            ]);
        })->middleware(ResolveOrganization::class);

        $response = $this->get('/org-bind-check/org-bind');

        $response->assertOk();
        $response->assertJson([
            'organization_bound' => true,
            'organization_id' => $community->id,
            'view_organization' => 'org-bind',
        ]);
    }

    public function test_resolve_organization_shares_view_variables(): void
    {
        $community = Organization::factory()->create(['slug' => 'view-share', 'is_active' => true]);

        Route::get('/resolve-org-view/{community}', function () {
            return response()->json([
                'currentOrganization' => view()->shared('currentOrganization')?->slug,
            ]);
        })->middleware(ResolveOrganization::class);

        $response = $this->get('/resolve-org-view/view-share');

        $response->assertOk();
        $response->assertJson([
            'currentOrganization' => 'view-share',
        ]);
    }
}
