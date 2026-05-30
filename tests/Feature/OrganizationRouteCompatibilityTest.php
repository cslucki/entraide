<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 1 route compatibility tests for the Community → Organization migration.
 *
 * Validates that routes using {organization} parameter are resolved correctly
 * while existing {community} routes remain fully operational.
 */
class OrganizationRouteCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Middleware: {organization} param resolution
    // -------------------------------------------------------------------------

    public function test_middleware_resolves_organization_route_parameter(): void
    {
        $community = Organization::factory()->create(['slug' => 'my-org', 'is_active' => true]);

        Route::get('/_test/org/{organization}', function () {
            return response()->json(['id' => app('current_organization')->id]);
        })->middleware(ResolveOrganization::class);

        $this->get('/_test/org/my-org')->assertOk()->assertJson(['id' => $community->id]);
    }

    public function test_middleware_resolves_community_param_still_works(): void
    {
        $community = Organization::factory()->create(['slug' => 'legacy-slug', 'is_active' => true]);

        Route::get('/c/{community}', function () {
            return response()->json(['id' => app('current_organization')->id]);
        })->middleware(ResolveOrganization::class);

        $this->get('/c/legacy-slug')->assertOk()->assertJson(['id' => $community->id]);
    }

    public function test_organization_param_binds_current_organization(): void
    {
        $community = Organization::factory()->create(['slug' => 'both-keys', 'is_active' => true]);

        Route::get('/_test/org/{organization}', function () {
            return response()->json([
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveOrganization::class);

        $this->get('/_test/org/both-keys')
            ->assertOk()
            ->assertJson([
                'organization_id' => $community->id,
            ]);
    }

    public function test_organization_param_returns_404_for_unknown_slug(): void
    {
        Route::get('/org/{organization}', fn () => response('ok'))
            ->middleware(ResolveOrganization::class);

        $this->get('/org/nonexistent')->assertNotFound();
    }

    public function test_organization_param_returns_404_for_inactive(): void
    {
        Organization::factory()->create(['slug' => 'inactive-org', 'is_active' => false]);

        Route::get('/org/{organization}', fn () => response('ok'))
            ->middleware(ResolveOrganization::class);

        $this->get('/org/inactive-org')->assertNotFound();
    }

    public function test_community_param_takes_precedence_when_both_present(): void
    {
        $communitySlug = Organization::factory()->create(['slug' => 'comm-slug', 'is_active' => true]);

        Route::get('/test/{community}/{organization}', function () {
            return response()->json(['id' => app('current_organization')->id]);
        })->middleware(ResolveOrganization::class);

        $this->get('/test/comm-slug/anything')
            ->assertOk()
            ->assertJson(['id' => $communitySlug->id]);
    }

    // -------------------------------------------------------------------------
    // Model-level compatibility: getRouteKeyName
    // -------------------------------------------------------------------------

    public function test_organization_route_key_name_is_slug(): void
    {
        $org = new Organization;
        $this->assertEquals('slug', $org->getRouteKeyName());
    }

    public function test_route_key_name_is_unchanged(): void
    {
        $org = new Organization;
        $this->assertEquals('slug', $org->getRouteKeyName());
    }

    // -------------------------------------------------------------------------
    // Community alias removed — 'organization' is now the only alias
    // -------------------------------------------------------------------------

    public function test_organization_alias_resolves_tenant(): void
    {
        $organization = Organization::factory()->create(['slug' => 'org-tenant', 'is_active' => true]);

        Route::get('/org-alias/{organization}', function () {
            return response()->json([
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware('organization');

        $response = $this->get('/org-alias/org-tenant');

        $response->assertOk();
        $this->assertEquals(
            $organization->id,
            $response->json('organization_id')
        );
    }
}
