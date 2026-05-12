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
 * - neither bound = no scope applied
 * - behavior is identical whether community_id is set via Organization or Community
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
    // No binding — no scope applied
    // -------------------------------------------------------------------------

    public function test_scope_not_applied_when_neither_is_bound(): void
    {
        $org = Community::factory()->create();
        $other = Community::factory()->create();

        $user = User::factory()->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $org->id]);
        Service::factory()->forUser($user)->create(['community_id' => $other->id]);

        // Neither bound — no WHERE clause, all rows visible
        $this->assertCount(2, Service::all());
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
}
