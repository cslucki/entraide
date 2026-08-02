<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK1075JoinRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organization
    {
        return Organization::factory()->create(['loops_enabled' => true]);
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    public function test_owner_can_accept_a_join_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertRedirect();

        $joinRequest->refresh();
        $this->assertSame('accepted', $joinRequest->status);
        $this->assertSame($owner->id, $joinRequest->decided_by);
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $applicant->id,
            'status' => 'active',
            'role' => 'member',
        ]);
    }

    public function test_owner_can_reject_a_join_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loop-join-requests.reject', $joinRequest))
            ->assertRedirect();

        $this->assertSame('rejected', $joinRequest->fresh()->status);
        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $applicant->id,
        ]);
    }

    public function test_organization_admin_can_accept_and_reject(): void
    {
        $orgAdmin = User::factory()->create();
        $org = $this->org();
        $org->update(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $org->id]);
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $applicant1 = User::factory()->create(['organization_id' => $org->id]);
        $applicant2 = User::factory()->create(['organization_id' => $org->id]);
        $request1 = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant1->id]);
        $request2 = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant2->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($orgAdmin->fresh())->post(route('loop-join-requests.accept', $request1))->assertRedirect();
        $this->actingAs($orgAdmin->fresh())->post(route('loop-join-requests.reject', $request2))->assertRedirect();

        $this->assertSame('accepted', $request1->fresh()->status);
        $this->assertSame('rejected', $request2->fresh()->status);
    }

    public function test_standard_member_cannot_accept_a_join_request(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertForbidden();

        $this->assertSame('pending', $joinRequest->fresh()->status);
    }

    public function test_cannot_accept_from_another_organization(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $otherOrg->id]);
        $applicant = User::factory()->create(['organization_id' => $otherOrg->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $otherOrg->id, 'user_id' => $applicant->id]);
        $outsiderOwner = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($outsiderOwner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertNotFound();
    }

    public function test_double_accept_does_not_double_process(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest))->assertRedirect();
        // Second accept on the now-decided request: refused gracefully (error flash, no crash),
        // and does NOT create a duplicate membership.
        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest))->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, LoopMember::where('loop_id', $loop->id)->where('user_id', $applicant->id)->count());
    }

    public function test_pending_requests_badge_visible_to_owner_on_show_page(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        LoopJoinRequest::factory()->create([
            'loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id, 'message' => 'Salut !',
        ]);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.join_requests_title'))
            ->assertSee('Salut !');
    }

    public function test_pending_requests_not_visible_to_standard_member(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        LoopJoinRequest::factory()->create(['loop_id' => $loop->id, 'organization_id' => $org->id, 'user_id' => $applicant->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($member)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertDontSee(__('loops.join_requests_title'));
    }
}
