<?php

namespace Tests\Feature;

use App\Events\MemberActivated;
use App\Events\MemberInvited;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\RewardDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RewardDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $referrer;

    private User $referred;

    private RewardDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->referrer = User::factory()->create(['organization_id' => $this->org->id]);
        $this->referred = User::factory()->create(['organization_id' => $this->org->id]);
        $this->dispatcher = new RewardDispatcher;

        app()->instance('current_organization', $this->org);
    }

    public function test_handle_invited_creates_referral(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $this->assertNotNull($referral->id);
        $this->assertEquals($this->referrer->id, $referral->referrer_user_id);
        $this->assertEquals($this->referred->id, $referral->referred_user_id);
        $this->assertEquals($this->org->id, $referral->organization_id);
        $this->assertEquals(1, $referral->depth);
        $this->assertEquals('pending', $referral->status);
    }

    public function test_handle_invited_creates_referrer_reward(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $reward = ReferralReward::where('referral_id', $referral->id)
            ->where('user_id', $this->referrer->id)
            ->first();

        $this->assertNotNull($reward);
        $this->assertEquals('member_invited', $reward->event_type);
        $this->assertEquals(1, $reward->level);
        $this->assertEquals(config('referral.rewards.invitation.level_1_referrer'), $reward->points);
        $this->assertEquals($this->referred->id, $reward->source_user_id);
        $this->assertEquals($this->org->id, $reward->organization_id);
    }

    public function test_handle_invited_creates_welcome_reward(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $reward = ReferralReward::where('referral_id', $referral->id)
            ->where('user_id', $this->referred->id)
            ->first();

        $this->assertNotNull($reward);
        $this->assertEquals('member_invited', $reward->event_type);
        $this->assertEquals(1, $reward->level);
        $this->assertEquals(config('referral.rewards.invitation.welcome'), $reward->points);
        $this->assertEquals($this->referrer->id, $reward->source_user_id);
    }

    public function test_handle_invited_propagates_metadata(): void
    {
        $metadata = ['source' => 'link', 'campaign' => 'spring2026'];
        $event = new MemberInvited($this->referrer, $this->referred, metadata: $metadata);
        $referral = $this->dispatcher->handleInvited($event);

        $rewards = ReferralReward::where('referral_id', $referral->id)->get();
        foreach ($rewards as $reward) {
            $this->assertEquals($metadata, $reward->metadata);
        }
    }

    public function test_handle_invited_increments_points_balance(): void
    {
        $initial = $this->referrer->points_balance;
        $event = new MemberInvited($this->referrer, $this->referred);
        $this->dispatcher->handleInvited($event);

        $this->referrer->refresh();
        $this->referred->refresh();

        $this->assertEquals($initial + config('referral.rewards.invitation.level_1_referrer'), $this->referrer->points_balance);
        $this->assertEquals($initial + config('referral.rewards.invitation.welcome'), $this->referred->points_balance);
    }

    public function test_handle_invited_returns_two_rewards(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $this->assertCount(2, $referral->rewards);
    }

    public function test_handle_invited_rejects_self_referral(): void
    {
        $event = new MemberInvited($this->referrer, $this->referrer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Self-referral is not allowed.');

        $this->dispatcher->handleInvited($event);
    }

    public function test_handle_invited_rejects_duplicate_pair(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $this->dispatcher->handleInvited($event);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate referral pair in this organization.');

        $this->dispatcher->handleInvited($event);
    }

    public function test_handle_invited_rejects_without_organization(): void
    {
        $userWithoutOrg = User::factory()->create();
        $userWithoutOrg->updateQuietly(['organization_id' => null]);
        $event = new MemberInvited($userWithoutOrg, $this->referred);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Organization context required for referral.');

        $this->dispatcher->handleInvited($event);
    }

    public function test_handle_invited_same_pair_allowed_in_different_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $referrerA = User::factory()->create(['organization_id' => $orgA->id]);
        $referredA = User::factory()->create(['organization_id' => $orgA->id]);
        $referrerB = User::factory()->create(['organization_id' => $orgB->id]);
        $referredB = User::factory()->create(['organization_id' => $orgB->id]);

        app()->instance('current_organization', $orgA);
        $eventA = new MemberInvited($referrerA, $referredA, organizationId: $orgA->id);
        $this->dispatcher->handleInvited($eventA);

        app()->instance('current_organization', $orgB);
        $eventB = new MemberInvited($referrerB, $referredB, organizationId: $orgB->id);
        $referralB = $this->dispatcher->handleInvited($eventB);

        $this->assertNotNull($referralB->id);
        $this->assertEquals($orgB->id, $referralB->organization_id);
    }

    public function test_handle_invited_rejects_direct_cross_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $orgA->id]);
        $referred = User::factory()->create(['organization_id' => $orgB->id]);

        $event = new MemberInvited($referrer, $referred, organizationId: $orgA->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-organization referral is not allowed.');

        $this->dispatcher->handleInvited($event);
    }

    // -------------------------------------------------------------------------
    // handleActivated
    // -------------------------------------------------------------------------

    public function test_handle_activated_updates_referral_status(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $referral->refresh();
        $this->assertEquals('activated', $referral->status);
        $this->assertNotNull($referral->activated_at);
    }

    public function test_handle_activated_creates_activation_reward(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $reward = ReferralReward::where('referral_id', $referral->id)
            ->where('user_id', $this->referrer->id)
            ->where('event_type', 'member_activated')
            ->first();

        $this->assertNotNull($reward);
        $this->assertEquals(1, $reward->level);
        $this->assertEquals(config('referral.rewards.activation.level_1_referrer'), $reward->points);
        $this->assertEquals($this->referred->id, $reward->source_user_id);
        $this->assertEquals($this->org->id, $reward->organization_id);
    }

    public function test_handle_activated_increments_referrer_points(): void
    {
        $initial = $this->referrer->points_balance;
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $this->referrer->refresh();
        $this->assertEquals(
            $initial + config('referral.rewards.invitation.level_1_referrer') + config('referral.rewards.activation.level_1_referrer'),
            $this->referrer->points_balance
        );
    }

    public function test_handle_activated_propagates_metadata(): void
    {
        $metadata = ['source' => 'email', 'campaign' => 'onboarding'];
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $this->dispatcher->handleActivated(new MemberActivated($this->referred, $metadata));

        $reward = ReferralReward::where('referral_id', $referral->id)
            ->where('event_type', 'member_activated')
            ->first();
        $this->assertEquals($metadata, $reward->metadata);
    }

    public function test_handle_activated_does_not_double_reward(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $this->dispatcher->handleInvited($event);

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));
        $rewardsAfterFirst = ReferralReward::count();

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));
        $rewardsAfterSecond = ReferralReward::count();

        $this->assertEquals($rewardsAfterFirst, $rewardsAfterSecond);
    }

    public function test_handle_activated_noop_without_pending_referral(): void
    {
        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $this->assertEquals(0, ReferralReward::count());
        $this->assertEquals(0, Referral::where('status', 'activated')->count());
    }

    public function test_handle_activated_preserves_organization_context(): void
    {
        $event = new MemberInvited($this->referrer, $this->referred);
        $referral = $this->dispatcher->handleInvited($event);

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $reward = ReferralReward::where('referral_id', $referral->id)
            ->where('event_type', 'member_activated')
            ->first();
        $this->assertEquals($this->org->id, $reward->organization_id);
    }

    public function test_handle_activated_with_l2_chain(): void
    {
        $org = Organization::factory()->create();
        $gpa = User::factory()->create(['organization_id' => $org->id]);
        $parent = User::factory()->create(['organization_id' => $org->id]);
        $child = User::factory()->create(['organization_id' => $org->id]);
        $initialGpa = $gpa->points_balance;
        $initialParent = $parent->points_balance;
        $initialChild = $child->points_balance;

        app()->instance('current_organization', $org);

        // gpa invites parent
        $this->dispatcher->handleInvited(new MemberInvited($gpa, $parent, organizationId: $org->id));
        // parent activates -> triggers L1 activation reward for gpa
        $this->dispatcher->handleActivated(new MemberActivated($parent));
        // parent invites child -> triggers L2 invite reward for gpa
        $this->dispatcher->handleInvited(new MemberInvited($parent, $child, organizationId: $org->id));
        // child activates -> triggers L1 activation for parent + L2 activation for gpa
        $this->dispatcher->handleActivated(new MemberActivated($child));

        // L2 referral exists and is activated
        $l2 = Referral::where('referrer_user_id', $gpa->id)
            ->where('referred_user_id', $child->id)
            ->first();
        $this->assertNotNull($l2);
        $this->assertEquals(2, $l2->depth);
        $this->assertEquals('activated', $l2->status);
        $this->assertNotNull($l2->activated_at);

        // L2 activation reward for grandparent
        $l2Reward = ReferralReward::where('referral_id', $l2->id)
            ->where('event_type', 'member_activated')
            ->where('level', 2)
            ->first();
        $this->assertNotNull($l2Reward);
        $this->assertEquals(config('referral.rewards.activation.level_2_referrer'), $l2Reward->points);
        $this->assertEquals($child->id, $l2Reward->source_user_id);

        // Points verification
        $gpa->refresh();
        $parent->refresh();
        $child->refresh();

        $this->assertEquals(
            $initialGpa + config('referral.rewards.invitation.level_1_referrer') + config('referral.rewards.activation.level_1_referrer')
                + config('referral.rewards.invitation.level_2_referrer') + config('referral.rewards.activation.level_2_referrer'),
            $gpa->points_balance
        );
        $this->assertEquals(
            $initialParent + config('referral.rewards.invitation.welcome') + config('referral.rewards.invitation.level_1_referrer')
                + config('referral.rewards.activation.level_1_referrer'),
            $parent->points_balance
        );
        $this->assertEquals(
            $initialChild + config('referral.rewards.invitation.welcome'),
            $child->points_balance
        );
    }

    public function test_handle_activated_multiple_pending_referrals(): void
    {
        $referrerA = User::factory()->create(['organization_id' => $this->org->id]);
        $referrerB = User::factory()->create(['organization_id' => $this->org->id]);
        $referred = User::factory()->create(['organization_id' => $this->org->id]);
        $initialA = $referrerA->points_balance;
        $initialB = $referrerB->points_balance;

        // Two different referrers refer the same user in the same org
        $this->dispatcher->handleInvited(new MemberInvited($referrerA, $referred));
        $this->dispatcher->handleInvited(new MemberInvited($referrerB, $referred));

        $this->dispatcher->handleActivated(new MemberActivated($referred));

        $referrerA->refresh();
        $referrerB->refresh();

        // Both referrers received invite + activation reward
        $this->assertEquals(
            $initialA + config('referral.rewards.invitation.level_1_referrer') + config('referral.rewards.activation.level_1_referrer'),
            $referrerA->points_balance
        );
        $this->assertEquals(
            $initialB + config('referral.rewards.invitation.level_1_referrer') + config('referral.rewards.activation.level_1_referrer'),
            $referrerB->points_balance
        );
    }

    // -------------------------------------------------------------------------
    // PointLedger integration
    // -------------------------------------------------------------------------

    public function test_handle_invited_creates_point_ledger_entries(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));

        $this->assertEquals(2, PointLedger::count());
    }

    public function test_handle_activated_creates_point_ledger_entries(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));
        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $ledgerCount = PointLedger::count();
        $this->assertEquals(3, $ledgerCount);
    }

    public function test_point_ledger_transaction_id_is_null(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));
        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $entries = PointLedger::all();
        foreach ($entries as $entry) {
            $this->assertNull($entry->transaction_id);
        }
    }

    public function test_point_ledger_delta_matches_reward_points(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));

        $referrerEntries = PointLedger::where('user_id', $this->referrer->id)->get();
        $this->assertCount(1, $referrerEntries);
        $this->assertEquals(config('referral.rewards.invitation.level_1_referrer'), $referrerEntries->first()->delta);

        $referredEntries = PointLedger::where('user_id', $this->referred->id)->get();
        $this->assertCount(1, $referredEntries);
        $this->assertEquals(config('referral.rewards.invitation.welcome'), $referredEntries->first()->delta);

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $referrerEntries = PointLedger::where('user_id', $this->referrer->id)->get();
        $this->assertCount(2, $referrerEntries);
        $this->assertEquals(
            config('referral.rewards.invitation.level_1_referrer') + config('referral.rewards.activation.level_1_referrer'),
            $referrerEntries->sum('delta')
        );
    }

    public function test_point_ledger_reason_is_referral_reward(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));
        $this->dispatcher->handleActivated(new MemberActivated($this->referred));

        $entries = PointLedger::all();
        foreach ($entries as $entry) {
            $this->assertEquals('referral_reward', $entry->reason);
        }
    }

    public function test_point_ledger_user_id_matches_rewarded_user(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));

        $referrerEntry = PointLedger::where('user_id', $this->referrer->id)->first();
        $this->assertNotNull($referrerEntry);

        $referredEntry = PointLedger::where('user_id', $this->referred->id)->first();
        $this->assertNotNull($referredEntry);
    }

    public function test_double_activation_does_not_double_ledger(): void
    {
        $this->dispatcher->handleInvited(new MemberInvited($this->referrer, $this->referred));
        $this->dispatcher->handleActivated(new MemberActivated($this->referred));
        $ledgerAfterFirst = PointLedger::count();

        $this->dispatcher->handleActivated(new MemberActivated($this->referred));
        $ledgerAfterSecond = PointLedger::count();

        $this->assertEquals($ledgerAfterFirst, $ledgerAfterSecond);
    }

    public function test_point_ledger_count_with_l2_chain(): void
    {
        $org = Organization::factory()->create();
        $gpa = User::factory()->create(['organization_id' => $org->id]);
        $parent = User::factory()->create(['organization_id' => $org->id]);
        $child = User::factory()->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $this->dispatcher->handleInvited(new MemberInvited($gpa, $parent, organizationId: $org->id));
        $this->assertEquals(2, PointLedger::count(), 'gpa→parent invite: 2 ledger entries');

        $this->dispatcher->handleActivated(new MemberActivated($parent));
        $this->assertEquals(3, PointLedger::count(), 'parent activation: +1 ledger entry');

        $this->dispatcher->handleInvited(new MemberInvited($parent, $child, organizationId: $org->id));
        $this->assertEquals(6, PointLedger::count(), 'parent→child invite with L2: +3 ledger entries');

        $this->dispatcher->handleActivated(new MemberActivated($child));
        $this->assertEquals(8, PointLedger::count(), 'child activation with L2: +2 ledger entries');
    }
}
