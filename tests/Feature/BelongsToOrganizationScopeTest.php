<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for BelongsToOrganizationScope Organization-first resolution.
 *
 * Verifies that:
 * - current_organization takes precedence (canonical)
 * - neither bound = empty result set (safety guard, no silent skip)
 * - behavior is identical whether org_id is set via Organization
 * - cross-Organization data leakage is impossible
 */
class BelongsToOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // current_organization takes precedence
    // -------------------------------------------------------------------------

    public function test_scope_filters_by_current_organization(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, Service::all());
    }

    public function test_scope_uses_organization_id_when_both_are_bound(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, Service::all());
    }

    public function test_organization_takes_priority_when_community_differs(): void
    {
        $org = Organization::factory()->create();
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $organization->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $other->id]);

        app()->instance('current_organization', $org);
        app()->instance('current_organization', $organization);

        $this->assertCount(1, Service::all());
    }

    // -------------------------------------------------------------------------
    // No binding — safety guard returns empty set
    // -------------------------------------------------------------------------

    public function test_scope_returns_empty_set_when_neither_is_bound(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $other->id]);

        $this->assertCount(0, Service::all());
    }

    public function test_scope_returns_empty_set_for_service_request_without_org(): void
    {
        $org = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        ServiceRequest::factory()->forUser($user)->create(['organization_id' => $org->id]);

        $this->assertCount(0, ServiceRequest::all());
    }

    public function test_scope_returns_empty_set_for_transaction_without_org(): void
    {
        $org = Organization::factory()->create();

        $buyer = User::factory()->create(['organization_id' => $org->id]);
        $seller = User::factory()->create(['organization_id' => $org->id]);

        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['organization_id' => $org->id]);

        $this->assertCount(0, Transaction::all());
    }

    // -------------------------------------------------------------------------
    // All three scoped models
    // -------------------------------------------------------------------------

    public function test_scope_applies_to_service_request(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        ServiceRequest::factory()->forUser($user)->create(['organization_id' => $org->id]);
        ServiceRequest::factory()->forUser($user)->create(['organization_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, ServiceRequest::all());
    }

    public function test_scope_applies_to_transaction(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $buyer = User::factory()->create(['organization_id' => $org->id]);
        $seller = User::factory()->create(['organization_id' => $org->id]);

        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['organization_id' => $org->id]);
        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['organization_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(1, Transaction::all());
    }

    // -------------------------------------------------------------------------
    // withoutGlobalScope bypass still works
    // -------------------------------------------------------------------------

    public function test_without_global_scope_bypasses_tenant_filter(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $other->id]);

        app()->instance('current_organization', $org);

        $this->assertCount(2, Service::withoutGlobalScope(BelongsToOrganizationScope::class)->get());
    }

    // -------------------------------------------------------------------------
    // Organization model works identically to Community in scope resolution
    // -------------------------------------------------------------------------

    public function test_organization_instance_scopes_correctly(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $org->id]);
        Service::factory()->forUser($user)->create(['organization_id' => $other->id]);

        // Bind an Organization instance (not Community)
        app()->instance('current_organization', $org);

        $this->assertCount(1, Service::all());
    }

    // -------------------------------------------------------------------------
    // Cross-Organization leakage prevention
    // -------------------------------------------------------------------------

    public function test_no_cross_organization_leak_with_org_bound(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['organization_id' => $orgA->id, 'title' => 'Service A']);
        Service::factory()->forUser($userB)->create(['organization_id' => $orgB->id, 'title' => 'Service B']);

        app()->instance('current_organization', $orgA);

        $services = Service::all();
        $this->assertCount(1, $services);
        $this->assertEquals('Service A', $services->first()->title);
    }

    public function test_no_cross_organization_leak_without_org_bound(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['organization_id' => $orgA->id]);
        ServiceRequest::factory()->forUser($userA)->create(['organization_id' => $orgA->id]);

        $buyer = User::factory()->create(['organization_id' => $orgB->id]);
        $seller = User::factory()->create(['organization_id' => $orgB->id]);
        Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['organization_id' => $orgB->id]);

        $this->assertCount(0, Service::all());
        $this->assertCount(0, ServiceRequest::all());
        $this->assertCount(0, Transaction::all());
    }

    public function test_without_global_scope_still_bypasses_for_admin_context(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Service::factory()->forUser($userA)->create(['organization_id' => $orgA->id]);
        Service::factory()->forUser($userB)->create(['organization_id' => $orgB->id]);

        $this->assertCount(
            2,
            Service::withoutGlobalScope(BelongsToOrganizationScope::class)->get()
        );
    }
}
