<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveCommunity;
use App\Models\Community;
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

    public function test_organization_extends_community(): void
    {
        $this->assertInstanceOf(Community::class, new Organization);
    }

    public function test_organization_uses_communities_table(): void
    {
        $org = new Organization;
        $this->assertEquals('communities', $org->getTable());
    }

    public function test_organization_factory_creates_persisted_record(): void
    {
        $org = Organization::factory()->create();

        $this->assertNotNull($org->id);
        $this->assertDatabaseHas('communities', ['id' => $org->id]);
    }

    public function test_organization_and_community_share_same_table_data(): void
    {
        $community = Community::factory()->create(['name' => 'Test Org']);

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

    public function test_organization_is_instance_of_community(): void
    {
        $org = Organization::factory()->create();

        $this->assertInstanceOf(Community::class, $org);
        $this->assertInstanceOf(Organization::class, $org);
    }

    // -------------------------------------------------------------------------
    // Middleware compatibility
    // -------------------------------------------------------------------------

    public function test_resolve_community_middleware_binds_current_organization(): void
    {
        $community = Community::factory()->create(['slug' => 'test-boucle', 'is_active' => true]);

        Route::get('/test-org-bind/{community}', function () {
            return response()->json([
                'community_id' => app('current_community')->id,
                'organization_id' => app('current_organization')->id,
            ]);
        })->middleware(ResolveCommunity::class);

        $response = $this->get('/test-org-bind/test-boucle');

        $response->assertOk();
        $response->assertJson([
            'community_id' => $community->id,
            'organization_id' => $community->id,
        ]);
    }

    public function test_current_organization_is_same_instance_as_current_community(): void
    {
        $community = Community::factory()->create(['slug' => 'same-instance', 'is_active' => true]);

        Route::get('/test-same-instance/{community}', function () {
            return response()->json([
                'same' => app('current_community') === app('current_organization'),
            ]);
        })->middleware(ResolveCommunity::class);

        $response = $this->get('/test-same-instance/same-instance');

        $response->assertOk();
        $response->assertJson(['same' => true]);
    }

    public function test_middleware_returns_404_for_unknown_slug(): void
    {
        Route::get('/test-404/{community}', fn () => response('ok'))
            ->middleware(ResolveCommunity::class);

        $response = $this->get('/test-404/nonexistent-org');

        $response->assertNotFound();
    }

    public function test_organization_middleware_alias_is_registered(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayHasKey('organization', $aliases);
        $this->assertEquals(ResolveCommunity::class, $aliases['organization']);
    }

    public function test_community_middleware_alias_remains_unchanged(): void
    {
        $aliases = app('router')->getMiddleware();

        $this->assertArrayHasKey('community', $aliases);
        $this->assertEquals(ResolveCommunity::class, $aliases['community']);
    }
}
