<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Organization;
use App\Models\Scopes\BelongsToTenantScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for BelongsToTenantScope Organization-first resolution.
 *
 * Verifies that:
 * - current_organization takes precedence (canonical)
 * - current_community works as legacy fallback
 * - neither bound = empty result set (safety guard, no silent skip)
 * - behavior is identical whether community_id is set via Organization or Community
 * - cross-Organization data leakage is impossible
 */
class BelongsToTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // current_organization takes precedence
    // -------------------------------------------------------------------------

    public function test_scope_filters_by_current_organization(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, Service::all());
    }

    public function test_scope_uses_organization_id_when_both_are_bound(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        // Bind both — organization takes precedence
        app()->instance('current_organization', $org);
        app()->instance('current_community', $org);

        $this->assertCount(1, Service::all());
    }

    // -------------------------------------------------------------------------
    // current_community legacy fallback
    // -------------------------------------------------------------------------

    public function test_scope_falls_back_to_current_community(): void
    {
        $community = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $community->id]);
        Service::factory()->forUser($user)->create(['community_id' => $community->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        // Only legacy binding — no current_organization
        app()->instance('current_community', $community);

        $this->assertCount(1, Service::all());
    }

    // -------------------------------------------------------------------------
    // No binding — safety guard returns empty set
    // -------------------------------------------------------------------------

    public function test_scope_returns_empty_set_when_neither_is_bound(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        $this->assertCount(0, Service::all());
    }

    public function test_scope_returns_empty_set_for_service_request_without_org(): void
    {
        $org = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        ServiceRequest::factory()->forUser($user)->create(['community_id' => $org->id]);

        $this->assertCount(0, ServiceRequest::all());
    }

    public function test_scope_returns_empty_set_for_transaction_without_org(): void
    {
        $org = Community::factory()->create();

        $buyer = User::factory()->create(['community_id' => $org->id]);
        $seller = User::factory()->create(['community_id' => $org->id]);

        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $org->id]);

        $this->assertCount(0, Transaction::all());
    }

    // -------------------------------------------------------------------------
    // All three scoped models
    // -------------------------------------------------------------------------

    public function test_scope_applies_to_service_request(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        ServiceRequest::factory()->forUser($user)->create(['community_id' => $org->id]);
        ServiceRequest::factory()->forUser($user)->create(['community_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, ServiceRequest::all());
    }

    public function test_scope_applies_to_transaction(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $buyer = User::factory()->create(['community_id' => $org->id]);
        $seller = User::factory()->create(['community_id' => $org->id]);

        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $org->id]);
        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, Transaction::all());
    }

    // -------------------------------------------------------------------------
    // withoutGlobalScope bypass still works
    // -------------------------------------------------------------------------

    public function test_without_global_scope_bypasses_tenant_filter(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(2, Service::withoutGlobalScope(BelongsToTenantScope::class)->get());
    }

    // -------------------------------------------------------------------------
    // Organization model works identically to Community in scope resolution
    // -------------------------------------------------------------------------

    public function test_organization_instance_scopes_identically_to_community(): void
    {
        $org = Organization::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        // Bind an Organization instance (not Community)
        app()->instance('current_organization', $org);

        $this->assertCount(1, Service::all());
    }

    // -------------------------------------------------------------------------
    // Cross-Organization leakage prevention
    // -------------------------------------------------------------------------

    public function test_no_cross_organization_leak_with_org_bound(): void
    {
        $orgA = Community::factory()->create();
        $orgB = Community::factory()->create();

        $userA = User::factory()->create(['community_id' => $orgA->id]);
        $userB = User::factory()->create(['community_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['community_id' => $orgA->id, 'title' => 'Service A']);
        Service::factory()->forUser($userB)->create(['community_id' => $orgB->id, 'title' => 'Service B']);

        app()->instance('current_organization', $orgA);

        $services = Service::all();
        $this->assertCount(1, $services);
        $this->assertEquals('Service A', $services->first()->title);
    }

    public function test_no_cross_organization_leak_without_org_bound(): void
    {
        $orgA = Community::factory()->create();
        $orgB = Community::factory()->create();

        $userA = User::factory()->create(['community_id' => $orgA->id]);
        $userB = User::factory()->create(['community_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['community_id' => $orgA->id]);
        ServiceRequest::factory()->forUser($userA)->create(['community_id' => $orgA->id]);

        $buyer = User::factory()->create(['community_id' => $orgB->id]);
        $seller = User::factory()->create(['community_id' => $orgB->id]);
        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $orgB->id]);

        $this->assertCount(0, Service::all());
        $this->assertCount(0, ServiceRequest::all());
        $this->assertCount(0, Transaction::all());
    }

    public function test_without_global_scope_still_bypasses_for_admin_context(): void
    {
        $orgA = Community::factory()->create();
        $orgB = Community::factory()->create();

        $userA = User::factory()->create(['community_id' => $orgA->id]);
        $userB = User::factory()->create(['community_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['community_id' => $orgA->id]);
        Service::factory()->forUser($userB)->create(['community_id' => $orgB->id]);

        $this->assertCount(
            2,
            Service::withoutGlobalScope(BelongsToTenantScope::class)->get()
        );
    }
}
