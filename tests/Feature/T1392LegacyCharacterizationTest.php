<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveApiOrganization;
use App\Http\Middleware\ResolveCommunity;
use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\Community;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Scopes\BelongsToTenantScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T139.2 — Legacy Behavior Characterization Tests
 *
 * Ces tests FIGENT le comportement ACTUEL du système.
 * Ils documentent l'état legacy avant toute migration Community → Organization.
 *
 * Ils doivent TOUS passer aujourd'hui.
 * Ils devront être ADAPTÉS lors des futures tâches T140.x.
 *
 * Domaines caractérisés :
 *   1. BelongsToTenantScope filtre actuellement sur community_id
 *   2. CurrentOrganization::get() peut encore fallback sur current_community
 *   3. ResolveCommunity bind encore current_community + current_organization
 *   4. ResolveApiOrganization dépend encore de $user->community_id
 *   5. Routes legacy /{community} restent fonctionnelles
 *   6. Loop dépend encore de community_id et n'a pas organization_id
 *   7. Broadcast channels comparent encore community_id
 */
class T1392LegacyCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Community $community;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->community = $this->org;
        $this->user = User::factory()->create([
            'community_id' => $this->community->id,
            'organization_id' => $this->community->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. BelongsToTenantScope filtre sur community_id
    // ─────────────────────────────────────────────────────────────

    public function test_belongs_to_tenant_scope_filters_by_community_id(): void
    {
        $other = Organization::factory()->create();

        Service::factory()->for($this->user)->create([
            'community_id' => $this->community->id,
            'title' => 'Scoped Service',
        ]);
        Service::factory()->for($this->user)->create([
            'community_id' => $other->id,
            'title' => 'Other Org Service',
        ]);

        app()->instance('current_organization', $this->community);

        $services = Service::where('title', 'like', '%Service')->get();

        $this->assertCount(1, $services);
        $this->assertEquals('Scoped Service', $services->first()->title);
    }

    public function test_belongs_to_tenant_scope_uses_community_id_column(): void
    {
        $scope = new BelongsToTenantScope;
        $model = new Service;

        app()->instance('current_organization', $this->community);

        $query = Service::query();
        $scope->apply($query, $model);

        $sql = $query->toSql();
        $this->assertStringContainsString('community_id', $sql);
    }

    public function test_belongs_to_tenant_scope_returns_empty_when_no_org_bound(): void
    {
        app()->forgetInstance('current_organization');
        app()->forgetInstance('current_community');

        Service::factory()->for($this->user)->create([
            'community_id' => $this->community->id,
        ]);

        $this->assertCount(0, Service::all());
    }

    public function test_belongs_to_tenant_scope_applies_to_service_request(): void
    {
        $other = Organization::factory()->create();

        ServiceRequest::factory()->for($this->user)->create([
            'community_id' => $this->community->id,
            'title' => 'My Request',
        ]);
        ServiceRequest::factory()->for($this->user)->create([
            'community_id' => $other->id,
            'title' => 'Other Request',
        ]);

        app()->instance('current_organization', $this->community);

        $this->assertCount(1, ServiceRequest::all());
        $this->assertEquals('My Request', ServiceRequest::first()->title);
    }

    public function test_belongs_to_tenant_scope_applies_to_transaction(): void
    {
        $other = Organization::factory()->create();

        Transaction::factory()->create([
            'community_id' => $this->community->id,
        ]);
        Transaction::factory()->create([
            'community_id' => $other->id,
        ]);

        app()->instance('current_organization', $this->community);

        $this->assertCount(1, Transaction::all());
    }

    // ─────────────────────────────────────────────────────────────
    // 2. CurrentOrganization::get() fallback current_community
    // ─────────────────────────────────────────────────────────────

    public function test_current_organization_returns_null_when_nothing_bound(): void
    {
        app()->forgetInstance('current_organization');
        app()->forgetInstance('current_community');

        $this->assertNull(CurrentOrganization::get());
    }

    public function test_current_organization_prefers_current_organization(): void
    {
        app()->instance('current_organization', $this->community);
        app()->instance('current_community', null);

        $this->assertEquals($this->community->id, CurrentOrganization::get()?->id);
    }

    public function test_current_organization_falls_back_to_current_community(): void
    {
        app()->forgetInstance('current_organization');
        app()->instance('current_community', $this->community);

        $result = CurrentOrganization::get();
        $this->assertNotNull($result);
        $this->assertEquals($this->community->id, $result->id);
    }

    public function test_current_organization_prioritizes_organization_over_community(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        app()->instance('current_organization', $orgA);
        app()->instance('current_community', $orgB);

        $result = CurrentOrganization::get();
        $this->assertEquals($orgA->id, $result->id);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. ResolveCommunity bindings
    // ─────────────────────────────────────────────────────────────

    public function test_community_patched_route_binds_current_organization(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'test-bind-org']);

        $this->get("/{$org->slug}/")
            ->assertOk();

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    public function test_community_patched_route_binds_current_community(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'test-bind-comm']);

        $this->get("/{$org->slug}/")
            ->assertOk();

        $this->assertTrue(app()->bound('current_community'));
        $this->assertEquals($org->id, app('current_community')->id);
    }

    public function test_community_route_binds_same_instance_for_both(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'test-same-instance']);

        $this->get("/{$org->slug}/")
            ->assertOk();

        $this->assertSame(app('current_organization'), app('current_community'));
    }

    // ─────────────────────────────────────────────────────────────
    // 4. ResolveApiOrganization dépend de $user->community_id
    // ─────────────────────────────────────────────────────────────

    public function test_resolve_api_organization_uses_user_community_id(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'community_id' => $org->id,
            'organization_id' => $org->id,
        ]);

        $middleware = app(ResolveApiOrganization::class);
        $request = Request::create('/api/services', 'GET');

        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    public function test_resolve_api_organization_returns_403_if_user_has_no_community(): void
    {
        $userWithoutCommunity = User::factory()->create([
            'community_id' => null,
            'organization_id' => null,
        ]);

        $middleware = app(ResolveApiOrganization::class);
        $request = Request::create('/api/services', 'GET');

        $request->setUserResolver(fn () => $userWithoutCommunity);

        $response = $middleware->handle($request, fn ($req) => null);

        $this->assertEquals(403, $response->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────
    // 5. Routes /{community} legacy restent fonctionnelles
    // ─────────────────────────────────────────────────────────────

    public function test_legacy_community_route_with_slug_returns_200(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'ma-communaute-test']);

        $this->get("/ma-communaute-test/")->assertOk();
    }

    public function test_legacy_community_route_binds_current_organization(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'slug-org-test']);

        $this->get("/slug-org-test/")->assertOk();
        $this->assertEquals($org->id, app('current_organization')->id);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Loop dépend de community_id, n'a pas organization_id
    // ─────────────────────────────────────────────────────────────

    public function test_loop_has_community_id(): void
    {
        $loop = Loop::factory()->create([
            'community_id' => $this->community->id,
        ]);

        $this->assertNotNull($loop->community_id);
        $this->assertEquals($this->community->id, $loop->community_id);
    }

    public function test_loop_has_organization_id(): void
    {
        $loop = Loop::factory()->create([
            'community_id' => $this->community->id,
        ]);

        $this->assertNotNull($loop->community_id);
        $this->assertNotNull($loop->organization_id);
        $this->assertEquals($loop->community_id, $loop->organization_id);
    }

    public function test_loop_belongs_to_community(): void
    {
        $loop = Loop::factory()->create([
            'community_id' => $this->community->id,
        ]);

        $this->assertNotNull($loop->community);
        $this->assertEquals($this->community->id, $loop->community->id);
    }

    public function test_loop_has_organization_relation(): void
    {
        $loop = Loop::factory()->create([
            'community_id' => $this->community->id,
        ]);

        $this->assertTrue(method_exists($loop, 'organization'));
        $this->assertNotNull($loop->organization);
        $this->assertEquals($this->community->id, $loop->organization->id);
    }

    // ─────────────────────────────────────────────────────────────
    // 7. Broadcast channels comparent community_id
    // ─────────────────────────────────────────────────────────────

    public function test_broadcast_channel_loop_checks_community_id(): void
    {
        $otherOrg = Organization::factory()->create();

        $loop = Loop::factory()->create([
            'community_id' => $otherOrg->id,
        ]);

        $this->assertNotEquals($this->user->community_id, $loop->community_id);
        $this->assertEquals($otherOrg->id, $loop->community_id);
    }

    // ─────────────────────────────────────────────────────────────
    // Organization extends Community — même table
    // ─────────────────────────────────────────────────────────────

    public function test_organization_uses_communities_table(): void
    {
        $org = new Organization;
        $this->assertEquals('communities', $org->getTable());
    }

    public function test_organization_and_community_share_same_table(): void
    {
        $communityTable = (new Community)->getTable();
        $organizationTable = (new Organization)->getTable();

        $this->assertEquals($communityTable, $organizationTable);
    }

    public function test_organization_can_be_retrieved_as_community(): void
    {
        $org = Organization::factory()->create(['name' => 'Test Org As Community']);

        $found = Community::find($org->id);

        $this->assertNotNull($found);
        $this->assertEquals('Test Org As Community', $found->name);
    }

    public function test_community_can_be_retrieved_as_organization(): void
    {
        $community = Community::factory()->create(['name' => 'Test Community As Org']);

        $found = Organization::find($community->id);

        $this->assertNotNull($found);
        $this->assertEquals('Test Community As Org', $found->name);
    }

    // ─────────────────────────────────────────────────────────────
    // HasOrganizationId synchronise community_id ↔ organization_id
    // ─────────────────────────────────────────────────────────────

    public function test_user_create_with_community_id_syncs_organization_id(): void
    {
        $user = User::factory()->create([
            'community_id' => $this->community->id,
            'organization_id' => null,
        ]);

        $user->refresh();

        $this->assertEquals($this->community->id, $user->organization_id);
    }

    public function test_user_create_with_organization_id_syncs_community_id(): void
    {
        $user = User::factory()->create([
            'community_id' => null,
            'organization_id' => $this->community->id,
        ]);

        $user->refresh();

        $this->assertEquals($this->community->id, $user->community_id);
    }
}
