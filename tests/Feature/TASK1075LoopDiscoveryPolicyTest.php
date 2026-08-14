<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TASK1075LoopDiscoveryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::factory()->create(['loops_enabled' => true]);
    }

    // ── viewWorkspace ────────────────────────────────────────────────────────

    public function test_active_member_can_view_workspace(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->assertTrue(Gate::forUser($user)->allows('viewWorkspace', $loop));
    }

    public function test_non_member_cannot_view_workspace(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('viewWorkspace', $loop));
    }

    public function test_organization_admin_without_membership_cannot_view_workspace(): void
    {
        $orgAdmin = User::factory()->create();
        $org = $this->org();
        $org->update(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $org->id]);
        $loop = Loop::factory()->create(['organization_id' => $org->id]);

        // Deliberate: workspace access is membership-only, no admin bypass.
        $this->assertFalse(Gate::forUser($orgAdmin->fresh())->allows('viewWorkspace', $loop));
    }

    public function test_super_admin_without_membership_cannot_view_workspace(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $superAdmin = User::factory()->create(['organization_id' => $org->id, 'is_admin' => true]);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('viewWorkspace', $loop));
    }

    public function test_cross_organization_user_cannot_view_workspace(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $otherOrg = $this->org();
        $outsider = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse(Gate::forUser($outsider)->allows('viewWorkspace', $loop));
    }

    // ── join (open access) ───────────────────────────────────────────────────

    public function test_org_member_can_join_open_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertTrue(Gate::forUser($user)->allows('join', $loop));
    }

    public function test_cannot_join_request_access_loop_directly(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('join', $loop));
    }

    public function test_cannot_join_invitation_access_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->invitationAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('join', $loop));
    }

    public function test_already_active_member_cannot_join_again(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->assertFalse(Gate::forUser($user)->allows('join', $loop));
    }

    public function test_banned_user_cannot_join_open_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id, 'banned_at' => now()]);

        $this->assertFalse(Gate::forUser($user)->allows('join', $loop));
    }

    public function test_cross_organization_user_cannot_join_open_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $otherOrg = $this->org();
        $outsider = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse(Gate::forUser($outsider)->allows('join', $loop));
    }

    public function test_cannot_join_archived_open_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->archived()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('join', $loop));
    }

    // ── requestToJoin ──────────────────────────────────────────────────────

    public function test_org_member_can_request_to_join_request_access_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertTrue(Gate::forUser($user)->allows('requestToJoin', $loop));
    }

    public function test_cannot_request_to_join_open_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->openAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('requestToJoin', $loop));
    }

    public function test_cannot_request_to_join_invitation_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->invitationAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('requestToJoin', $loop));
    }

    public function test_cannot_send_a_second_pending_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $user->id]);

        $this->assertFalse(Gate::forUser($user)->allows('requestToJoin', $loop));
    }

    public function test_active_member_cannot_request_to_join_their_own_loop(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $user->id, 'status' => 'active']);

        $this->assertFalse(Gate::forUser($user)->allows('requestToJoin', $loop));
    }

    // ── manageJoinRequests ───────────────────────────────────────────────────

    public function test_owner_can_manage_join_requests(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('manageJoinRequests', $loop));
    }

    public function test_organization_admin_can_manage_join_requests(): void
    {
        $orgAdmin = User::factory()->create();
        $org = $this->org();
        $org->update(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $org->id]);
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);

        $this->assertTrue(Gate::forUser($orgAdmin->fresh())->allows('manageJoinRequests', $loop));
    }

    public function test_standard_member_cannot_manage_join_requests(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);

        $this->assertFalse(Gate::forUser($member)->allows('manageJoinRequests', $loop));
    }
}
