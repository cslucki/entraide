<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Scopes\BelongsToTenantScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    // -------------------------------------------------------------------------
    // Basic creation
    // -------------------------------------------------------------------------

    public function test_can_create_referral(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();

        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
            'depth' => 1,
            'status' => 'pending',
        ]);

        $this->assertNotNull($referral->id);
        $this->assertEquals('pending', $referral->status);
        $this->assertEquals(1, $referral->depth);
    }

    public function test_can_create_referral_reward(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $reward = ReferralReward::create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'organization_id' => $this->org->id,
            'event_type' => 'member_invited',
            'level' => 1,
            'points' => 10,
        ]);

        $this->assertNotNull($reward->id);
        $this->assertEquals('member_invited', $reward->event_type);
        $this->assertEquals(10, $reward->points);
    }

    // -------------------------------------------------------------------------
    // Referral relations
    // -------------------------------------------------------------------------

    public function test_referral_belongs_to_referrer(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->assertInstanceOf(User::class, $referral->referrer);
        $this->assertEquals($referrer->id, $referral->referrer->id);
    }

    public function test_referral_belongs_to_referred(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->assertInstanceOf(User::class, $referral->referred);
        $this->assertEquals($referred->id, $referral->referred->id);
    }

    public function test_referral_belongs_to_organization(): void
    {
        $org = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $org->id]);
        $referred = User::factory()->create(['organization_id' => $org->id]);
        $referral = Referral::factory()->create([
            'organization_id' => $org->id,
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->assertInstanceOf(Organization::class, $referral->organization);
        $this->assertEquals($org->id, $referral->organization->id);
    }

    public function test_referral_has_parent_referral(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $third = User::factory()->create();
        $parent = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        $child = Referral::factory()->create([
            'referrer_user_id' => $referred->id,
            'referred_user_id' => $third->id,
            'parent_referral_id' => $parent->id,
            'organization_id' => $this->org->id,
            'depth' => 2,
        ]);

        $this->assertInstanceOf(Referral::class, $child->parentReferral);
        $this->assertEquals($parent->id, $child->parentReferral->id);
    }

    public function test_referral_has_children(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $third = User::factory()->create();
        $parent = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        $child = Referral::factory()->create([
            'referrer_user_id' => $referred->id,
            'referred_user_id' => $third->id,
            'parent_referral_id' => $parent->id,
            'organization_id' => $this->org->id,
            'depth' => 2,
        ]);

        $this->assertCount(1, $parent->children);
        $this->assertEquals($child->id, $parent->children->first()->id);
    }

    public function test_referral_has_rewards(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertCount(1, $referral->rewards);
        $this->assertInstanceOf(ReferralReward::class, $referral->rewards->first());
    }

    // -------------------------------------------------------------------------
    // User relations
    // -------------------------------------------------------------------------

    public function test_user_has_sent_referrals(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertCount(1, $referrer->sentReferrals);
        $this->assertInstanceOf(Referral::class, $referrer->sentReferrals->first());
    }

    public function test_user_has_received_referrals(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertCount(1, $referred->receivedReferrals);
        $this->assertInstanceOf(Referral::class, $referred->receivedReferrals->first());
    }

    public function test_user_has_referral_rewards(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertCount(1, $referrer->referralRewards);
        $this->assertInstanceOf(ReferralReward::class, $referrer->referralRewards->first());
    }

    // -------------------------------------------------------------------------
    // ReferralReward relations
    // -------------------------------------------------------------------------

    public function test_referral_reward_belongs_to_referral(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        $reward = ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertInstanceOf(Referral::class, $reward->referral);
        $this->assertEquals($referral->id, $reward->referral->id);
    }

    public function test_referral_reward_belongs_to_user(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        $reward = ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertInstanceOf(User::class, $reward->user);
        $this->assertEquals($referrer->id, $reward->user->id);
    }

    public function test_referral_reward_belongs_to_source_user(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        $reward = ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'source_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $this->assertInstanceOf(User::class, $reward->sourceUser);
        $this->assertEquals($referred->id, $reward->sourceUser->id);
    }

    public function test_referral_reward_belongs_to_organization(): void
    {
        $org = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $org->id]);
        $referred = User::factory()->create(['organization_id' => $org->id]);
        $referral = Referral::factory()->forOrganization($org)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);
        $reward = ReferralReward::factory()->forOrganization($org)->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
        ]);

        $this->assertInstanceOf(Organization::class, $reward->organization);
        $this->assertEquals($org->id, $reward->organization->id);
    }

    // -------------------------------------------------------------------------
    // Tenant isolation
    // -------------------------------------------------------------------------

    public function test_tenant_isolation_filters_by_current_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $referrer = User::factory()->create(['organization_id' => $orgA->id]);
        $referred = User::factory()->create(['organization_id' => $orgB->id]);

        Referral::factory()->forOrganization($orgA)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);
        Referral::factory()->forOrganization($orgB)->create([
            'referrer_user_id' => $referred->id,
            'referred_user_id' => $referrer->id,
        ]);

        app()->instance('current_organization', $orgA);

        $this->assertCount(1, Referral::all());
        $this->assertEquals($orgA->id, Referral::first()->organization_id);
    }

    public function test_tenant_isolation_respects_organization_boundary(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $referrer = User::factory()->create(['organization_id' => $orgA->id]);
        $referred = User::factory()->create(['organization_id' => $orgA->id]);
        $referredB = User::factory()->create(['organization_id' => $orgB->id]);

        Referral::factory()->forOrganization($orgA)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);
        Referral::factory()->forOrganization($orgB)->create([
            'referrer_user_id' => $referred->id,
            'referred_user_id' => $referredB->id,
        ]);

        app()->instance('current_organization', $orgA);

        $visible = Referral::all();
        $this->assertCount(1, $visible);
        $this->assertEquals($orgA->id, $visible->first()->organization_id);
    }

    public function test_tenant_isolation_can_be_bypassed(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $referrer = User::factory()->create(['organization_id' => $orgA->id]);
        $referred = User::factory()->create(['organization_id' => $orgB->id]);

        Referral::factory()->forOrganization($orgA)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);
        Referral::factory()->forOrganization($orgB)->create([
            'referrer_user_id' => $referred->id,
            'referred_user_id' => $referrer->id,
        ]);

        app()->instance('current_organization', $orgA);

        $this->assertCount(2, Referral::withoutGlobalScope(BelongsToTenantScope::class)->get());
    }

    // -------------------------------------------------------------------------
    // ReferralReward tenant isolation
    // -------------------------------------------------------------------------

    public function test_referral_reward_tenant_isolation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $orgA->id]);
        $referred = User::factory()->create(['organization_id' => $orgA->id]);

        $referralA = Referral::factory()->forOrganization($orgA)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);
        $referralB = Referral::factory()->forOrganization($orgB)->create([
            'referrer_user_id' => $referred->id,
            'referred_user_id' => $referrer->id,
        ]);

        ReferralReward::factory()->forOrganization($orgA)->create([
            'referral_id' => $referralA->id,
            'user_id' => $referrer->id,
        ]);
        ReferralReward::factory()->forOrganization($orgB)->create([
            'referral_id' => $referralB->id,
            'user_id' => $referred->id,
        ]);

        app()->instance('current_organization', $orgA);

        $this->assertCount(1, ReferralReward::all());
    }

    // -------------------------------------------------------------------------
    // Anti-abuse: duplicate prevention
    // -------------------------------------------------------------------------

    public function test_duplicate_referral_pair_is_prevented(): void
    {
        $org = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $org->id]);
        $referred = User::factory()->create(['organization_id' => $org->id]);

        Referral::factory()->forOrganization($org)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->expectException(QueryException::class);

        Referral::factory()->forOrganization($org)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);
    }

    public function test_same_pair_allowed_in_different_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $referrer = User::factory()->create();
        $referred = User::factory()->create();

        Referral::factory()->forOrganization($orgA)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        // Same pair in different organization — should succeed
        $referral = Referral::factory()->forOrganization($orgB)->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->assertNotNull($referral->id);
    }

    // -------------------------------------------------------------------------
    // Referral code on User
    // -------------------------------------------------------------------------

    public function test_user_can_have_referral_code(): void
    {
        $user = User::factory()->create([
            'referral_code' => 'cyril',
        ]);

        $this->assertEquals('cyril', $user->referral_code);
    }

    public function test_referral_code_is_nullable(): void
    {
        $user = User::factory()->create(['referral_code' => null]);

        $this->assertNull($user->referral_code);
    }

    public function test_referral_code_is_unique(): void
    {
        User::factory()->create(['referral_code' => 'alice92']);

        $this->expectException(QueryException::class);

        User::factory()->create(['referral_code' => 'alice92']);
    }

    // -------------------------------------------------------------------------
    // Metadata JSON on ReferralReward
    // -------------------------------------------------------------------------

    public function test_referral_reward_stores_json_metadata(): void
    {
        $referral = Referral::factory()->create(['organization_id' => $this->org->id]);
        $metadata = ['source' => 'link', 'campaign' => 'spring2026'];

        $reward = ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => User::factory(),
            'organization_id' => $this->org->id,
            'metadata' => $metadata,
        ]);

        $this->assertEquals($metadata, $reward->metadata);
    }

    public function test_referral_reward_metadata_is_nullable(): void
    {
        $referral = Referral::factory()->create(['organization_id' => $this->org->id]);
        $reward = ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => User::factory(),
            'organization_id' => $this->org->id,
            'metadata' => null,
        ]);

        $this->assertNull($reward->metadata);
    }

    // -------------------------------------------------------------------------
    // Cascading deletes
    // -------------------------------------------------------------------------

    public function test_deleting_user_cascades_to_referrals(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $referrer->delete();

        $this->assertDatabaseMissing('referrals', ['id' => $referral->id]);
    }

    public function test_deleting_referral_cascades_to_rewards(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);
        $reward = ReferralReward::factory()->create([
            'referral_id' => $referral->id,
            'user_id' => $referrer->id,
            'organization_id' => $this->org->id,
        ]);

        $referral->delete();

        $this->assertDatabaseMissing('referral_rewards', ['id' => $reward->id]);
    }

    // -------------------------------------------------------------------------
    // Referral status changes
    // -------------------------------------------------------------------------

    public function test_referral_can_be_activated(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
            'status' => 'pending',
        ]);

        $referral->update([
            'status' => 'activated',
            'activated_at' => now(),
        ]);

        $referral->refresh();
        $this->assertEquals('activated', $referral->status);
        $this->assertNotNull($referral->activated_at);
    }

    // -------------------------------------------------------------------------
    // Eager loading
    // -------------------------------------------------------------------------

    public function test_referral_relations_can_be_eager_loaded(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $loaded = Referral::with(['referrer', 'referred', 'rewards'])->find($referral->id);

        $this->assertTrue($loaded->relationLoaded('referrer'));
        $this->assertTrue($loaded->relationLoaded('referred'));
        $this->assertTrue($loaded->relationLoaded('rewards'));
    }

    public function test_user_referral_relations_can_be_eager_loaded(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'john']);
        $referred = User::factory()->create();
        Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'organization_id' => $this->org->id,
        ]);

        $user = User::with('sentReferrals')->find($referrer->id);

        $this->assertTrue($user->relationLoaded('sentReferrals'));
        $this->assertCount(1, $user->sentReferrals);
    }
}
