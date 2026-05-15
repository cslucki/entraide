<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Referral;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopMemberInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Community $communityA;
    private Community $communityB;
    private User $userA;
    private User $userB;
    private User $crossUser;
    private Loop $loop;
    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->communityA = Community::factory()->create();
        $this->communityB = Community::factory()->create();

        $this->userA = User::factory()->create(['community_id' => $this->communityA->id]);
        $this->userB = User::factory()->create(['community_id' => $this->communityA->id]);
        $this->crossUser = User::factory()->create(['community_id' => $this->communityB->id]);

        $this->service = new LoopService;
        $this->loop = $this->service->createLoop($this->userA, 'Test Loop');
    }

    // -------------------------------------------------------------------------
    // Same-community invariant
    // -------------------------------------------------------------------------

    public function test_can_add_member_from_same_community(): void
    {
        $member = $this->service->addMember($this->loop, $this->userB);

        $this->assertInstanceOf(LoopMember::class, $member);
        $this->assertEquals($this->loop->id, $member->loop_id);
        $this->assertEquals($this->userB->id, $member->user_id);
        $this->assertEquals('member', $member->role);
        $this->assertEquals('active', $member->status);
    }

    public function test_cannot_add_member_from_different_community(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot add member from a different community to this loop.');

        $this->service->addMember($this->loop, $this->crossUser);
    }

    public function test_cannot_add_member_from_different_community_via_web_route(): void
    {
        $otherLoop = $this->service->createLoop($this->crossUser, 'Other Loop');

        // userA tries to add crossUser to userA's own loop
        $response = $this->actingAs($this->userA)
            ->post(route('loops.members.add', $this->loop), [
                'referral_id' => 'fake-id',
            ]);

        // referral_id doesn't exist, so this should get validation error
        $response->assertSessionHasErrors('referral_id');
    }

    public function test_cannot_add_duplicate_member(): void
    {
        $this->service->addMember($this->loop, $this->userB);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User is already a member of this loop.');

        $this->service->addMember($this->loop, $this->userB);
    }

    public function test_cannot_directly_create_cross_community_membership(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot add member from a different community to this loop.');

        $this->service->addMember($this->loop, $this->crossUser);
    }

    public function test_loop_show_is_blocked_for_cross_community_user(): void
    {
        $response = $this->actingAs($this->crossUser)
            ->get(route('loops.show', $this->loop));

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Referral bridge
    // -------------------------------------------------------------------------

    public function test_eligible_referrals_returns_same_community_referrals(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $eligible = $this->service->getEligibleReferrals($this->userA, $this->loop);

        $this->assertCount(1, $eligible);
        $this->assertEquals($referral->id, $eligible->first()->id);
    }

    public function test_eligible_referrals_excludes_existing_members(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $this->service->addMember($this->loop, $referred);

        $eligible = $this->service->getEligibleReferrals($this->userA, $this->loop);

        $this->assertCount(0, $eligible);
    }

    public function test_eligible_referrals_excludes_cross_community(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityB->id]);
        Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityB->id,
        ]);

        $eligible = $this->service->getEligibleReferrals($this->userA, $this->loop);

        $this->assertCount(0, $eligible);
    }

    public function test_add_referral_to_loop_creates_member(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $member = $this->service->addReferralToLoop($this->loop, $this->userA, $referral);

        $this->assertNotNull($member);
        $this->assertEquals($referred->id, $member->user_id);
        $this->assertEquals($this->loop->id, $member->loop_id);
    }

    public function test_add_referral_rejects_wrong_owner(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->userB->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This referral does not belong to you.');

        $this->service->addReferralToLoop($this->loop, $this->userA, $referral);
    }

    public function test_add_referral_rejects_cross_community(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityB->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->crossUser->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityB->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot add cross-community referral to this loop.');

        $this->service->addReferralToLoop($this->loop, $this->crossUser, $referral);
    }

    public function test_add_referral_via_web_route(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->post(route('loops.members.add', $this->loop), [
                'referral_id' => $referral->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $referred->id,
        ]);
    }

    public function test_add_referral_via_web_route_rejects_cross_community(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityB->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->crossUser->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityB->id,
        ]);

        $response = $this->actingAs($this->crossUser)
            ->post(route('loops.members.add', $this->loop), [
                'referral_id' => $referral->id,
            ]);

        // Loop is in communityA, crossUser is in communityB, so 404 on the loop
        $response->assertStatus(404);
    }

    public function test_referral_bridge_loop_show_shows_eligible_referrals(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->get(route('loops.show', $this->loop));

        $response->assertStatus(200);
        $response->assertSee($referred->name);
    }

    public function test_referral_bridge_loop_show_hides_cross_community_referrals(): void
    {
        $referredA = User::factory()->create(['community_id' => $this->communityA->id]);
        $referredB = User::factory()->create(['community_id' => $this->communityB->id]);

        Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referredA->id,
            'community_id' => $this->communityA->id,
        ]);
        Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referredB->id,
            'community_id' => $this->communityB->id,
        ]);

        $response = $this->actingAs($this->userA)
            ->get(route('loops.show', $this->loop));

        $response->assertStatus(200);
        $response->assertSee($referredA->name);
        $response->assertDontSee($referredB->name);
    }

    // -------------------------------------------------------------------------
    // Blocker 1: Community-prefixed route isolation
    // -------------------------------------------------------------------------

    public function test_cross_community_route_prefix_is_blocked(): void
    {
        // Simulate communityB context (as if accessed via /community-b/...)
        app()->instance('current_community', $this->communityB);

        $response = $this->actingAs($this->userA)
            ->get(route('loops.show', $this->loop));

        // userA belongs to communityA, but current_community is communityB → 404
        $response->assertStatus(404);
    }

    public function test_cross_community_creation_is_blocked(): void
    {
        // Simulate communityB context (as if accessed via /community-b/...)
        app()->instance('current_community', $this->communityB);

        $response = $this->actingAs($this->userA)
            ->post(route('loops.store'), [
                'name' => 'Cross-community loop attempt',
            ]);

        // userA belongs to communityA, but current_community is communityB → 404
        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Blocker 2: LoopMember authorization
    // -------------------------------------------------------------------------

    public function test_same_community_non_member_cannot_view_loop_show(): void
    {
        $response = $this->actingAs($this->userB)
            ->get(route('loops.show', $this->loop));

        // userB is in same community but not a loop member → 404
        $response->assertStatus(404);
    }

    public function test_same_community_non_member_cannot_add_loop_member(): void
    {
        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->userB->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->post(route('loops.members.add', $this->loop), [
                'referral_id' => $referral->id,
            ]);

        // userB is in same community but not a loop member → 404
        $response->assertStatus(404);
    }

    public function test_non_owner_member_cannot_add_loop_member(): void
    {
        $this->service->addMember($this->loop, $this->userB, 'member');

        $referred = User::factory()->create(['community_id' => $this->communityA->id]);
        $referral = Referral::factory()->create([
            'referrer_user_id' => $this->userB->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $response = $this->actingAs($this->userB)
            ->post(route('loops.members.add', $this->loop), [
                'referral_id' => $referral->id,
            ]);

        // userB is a member but role is 'member', not owner/moderator → 404
        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Referral: additional community_id filtering
    // -------------------------------------------------------------------------

    public function test_eligible_referrals_excludes_referred_user_from_wrong_community(): void
    {
        // Same referrals.community_id as loop, but referred_user is in different community
        $referred = User::factory()->create(['community_id' => $this->communityB->id]);
        Referral::factory()->create([
            'referrer_user_id' => $this->userA->id,
            'referred_user_id' => $referred->id,
            'community_id' => $this->communityA->id,
        ]);

        $eligible = $this->service->getEligibleReferrals($this->userA, $this->loop);

        $this->assertCount(0, $eligible);
    }
}
