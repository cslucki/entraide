<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveCommunity;
use App\Models\Community;
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
        $community = Community::factory()->create(['slug' => 'my-org', 'is_active' => true]);

        Route::get('/org/{organization}', function () {
            return response()->json(['id' => app('current_organization')->id]);
        })->middleware(ResolveCommunity::class);

        $this->get('/org/my-org')->assertOk()->assertJson(['id' => $community->id]);
    }

    public function test_middleware_resolves_community_param_still_works(): void
    {
        $community = Community::factory()->create(['slug' => 'legacy-slug', 'is_active' => true]);

        Route::get('/c/{community}', function () {
            return response()->json(['id' => app('current_community')->id]);
        })->middleware(ResolveCommunity::class);

        $this->get('/c/legacy-slug')->assertOk()->assertJson(['id' => $community->id]);
    }

    public function test_organization_param_binds_both_current_keys(): void
    {
        $community = Community::factory()->create(['slug' => 'both-keys', 'is_active' => true]);

        Route::get('/org/{organization}', function () {
            return response()->json([
                'community_id' => app('current_community')->id,
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveCommunity::class);

        $this->get('/org/both-keys')
            ->assertOk()
            ->assertJson([
                'community_id' => $community->id,
                'organization_id' => $community->id,
            ]);
    }

    public function test_organization_param_returns_404_for_unknown_slug(): void
    {
        Route::get('/org/{organization}', fn () => response('ok'))
            ->middleware(ResolveCommunity::class);

        $this->get('/org/nonexistent')->assertNotFound();
    }

    public function test_organization_param_returns_404_for_inactive(): void
    {
        Community::factory()->create(['slug' => 'inactive-org', 'is_active' => false]);

        Route::get('/org/{organization}', fn () => response('ok'))
            ->middleware(ResolveCommunity::class);

        $this->get('/org/inactive-org')->assertNotFound();
    }

    public function test_community_param_takes_precedence_when_both_present(): void
    {
        $communitySlug = Community::factory()->create(['slug' => 'comm-slug', 'is_active' => true]);

        Route::get('/test/{community}/{organization}', function () {
            return response()->json(['id' => app('current_community')->id]);
        })->middleware(ResolveCommunity::class);

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

    public function test_community_route_key_name_is_unchanged(): void
    {
        $community = new Community;
        $this->assertEquals('id', $community->getRouteKeyName());
    }
}
