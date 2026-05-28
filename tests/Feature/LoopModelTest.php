<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Organization;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_loop_belongs_to_community_via_legacy_community_id(): void
    {
        $community = Organization::factory()->create();
        $loop = Loop::factory()->create(['organization_id' => $community->id]);

        $this->assertInstanceOf(Community::class, $loop->community);
        $this->assertEquals($community->id, $loop->community->id);
    }

    public function test_loop_belongs_to_creator(): void
    {
        $user = User::factory()->create();
        $loop = Loop::factory()->create(['created_by' => $user->id]);

        $this->assertInstanceOf(User::class, $loop->creator);
        $this->assertEquals($user->id, $loop->creator->id);
    }

    public function test_loop_creator_can_be_null(): void
    {
        $loop = Loop::factory()->create(['created_by' => null]);

        $this->assertNull($loop->creator);
    }

    public function test_loop_has_many_members(): void
    {
        $loop = Loop::factory()->create();
        $member1 = LoopMember::factory()->create(['loop_id' => $loop->id]);
        $member2 = LoopMember::factory()->create(['loop_id' => $loop->id]);

        $this->assertCount(2, $loop->members);
        $this->assertTrue($loop->members->contains($member1));
        $this->assertTrue($loop->members->contains($member2));
    }

    public function test_loop_member_belongs_to_loop(): void
    {
        $loop = Loop::factory()->create();
        $member = LoopMember::factory()->create(['loop_id' => $loop->id]);

        $this->assertInstanceOf(Loop::class, $member->loop);
        $this->assertEquals($loop->id, $member->loop->id);
    }

    public function test_loop_member_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $member = LoopMember::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $member->user);
        $this->assertEquals($user->id, $member->user->id);
    }

    public function test_user_has_many_loop_memberships(): void
    {
        $user = User::factory()->create();
        $membership1 = LoopMember::factory()->create(['user_id' => $user->id]);
        $membership2 = LoopMember::factory()->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->loopMemberships);
        $this->assertTrue($user->loopMemberships->contains($membership1));
        $this->assertTrue($user->loopMemberships->contains($membership2));
    }

    public function test_community_has_many_loops(): void
    {
        $community = Organization::factory()->create();
        $loop1 = Loop::factory()->create(['organization_id' => $community->id]);
        $loop2 = Loop::factory()->create(['organization_id' => $community->id]);

        $this->assertCount(2, $community->loops);
        $this->assertTrue($community->loops->contains($loop1));
        $this->assertTrue($community->loops->contains($loop2));
    }

    public function test_loop_membership_is_unique_per_loop_and_user(): void
    {
        $loop = Loop::factory()->create();
        $user = User::factory()->create();

        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_loop_slug_is_unique_per_community(): void
    {
        $community = Organization::factory()->create();

        Loop::factory()->create([
            'organization_id' => $community->id,
            'slug' => 'test-loop',
        ]);

        $this->expectException(QueryException::class);
        Loop::factory()->create([
            'organization_id' => $community->id,
            'slug' => 'test-loop',
        ]);
    }

    public function test_same_slug_allowed_in_different_communities(): void
    {
        $community1 = Organization::factory()->create();
        $community2 = Organization::factory()->create();

        Loop::factory()->create([
            'organization_id' => $community1->id,
            'slug' => 'test-loop',
        ]);

        $loop = Loop::factory()->create([
            'organization_id' => $community2->id,
            'slug' => 'test-loop',
        ]);

        $this->assertNotNull($loop);
        $this->assertEquals($community2->id, $loop->organization_id);
    }

    public function test_loop_is_not_tenant_boundary(): void
    {
        $community1 = Organization::factory()->create();
        $community2 = Organization::factory()->create();

        $loop1 = Loop::factory()->create(['organization_id' => $community1->id]);
        Loop::factory()->create(['organization_id' => $community2->id]);

        $loopsInCommunity1 = Loop::where('organization_id', $community1->id)->get();
        $this->assertCount(1, $loopsInCommunity1);
        $this->assertEquals($loop1->id, $loopsInCommunity1->first()->id);
    }

    public function test_loop_member_has_default_role(): void
    {
        $member = LoopMember::factory()->create();

        $this->assertEquals('member', $member->role);
    }

    public function test_loop_member_has_default_status(): void
    {
        $member = LoopMember::factory()->create();

        $this->assertEquals('active', $member->status);
    }

    public function test_loop_has_default_type(): void
    {
        $loop = Loop::factory()->create();

        $this->assertEquals('custom', $loop->type);
    }

    public function test_loop_has_default_status(): void
    {
        $loop = Loop::factory()->create();

        $this->assertEquals('active', $loop->status);
    }

    public function test_loop_member_joined_at_is_nullable(): void
    {
        $member = LoopMember::factory()->create(['joined_at' => null]);

        $this->assertNull($member->joined_at);
    }

    public function test_loop_active_members_scope(): void
    {
        $loop = Loop::factory()->create();
        LoopMember::factory()->create(['loop_id' => $loop->id, 'status' => 'active']);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'status' => 'invited']);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'status' => 'left']);

        $this->assertCount(1, $loop->activeMembers);
        $this->assertEquals('active', $loop->activeMembers->first()->status);
    }

    public function test_loop_on_delete_cascade_removes_members(): void
    {
        $loop = Loop::factory()->create();
        LoopMember::factory()->create(['loop_id' => $loop->id]);

        $loop->delete();

        $this->assertDatabaseMissing('loop_members', ['loop_id' => $loop->id]);
    }
}
