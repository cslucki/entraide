<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $referrer;

    private User $referred;

    private ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->referrer = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => 'john',
        ]);
        $this->referred = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->service = new ReferralService;

        app()->instance('current_organization', $this->org);
    }

    public function test_attribute_by_code_dispatches_invite_flow(): void
    {
        $referral = $this->service->attributeByCode($this->referred, 'john');

        $this->assertNotNull($referral->id);
        $this->assertEquals($this->referrer->id, $referral->referrer_user_id);
        $this->assertEquals($this->referred->id, $referral->referred_user_id);
        $this->assertEquals($this->org->id, $referral->organization_id);
        $this->assertEquals(1, $referral->depth);
        $this->assertEquals('pending', $referral->status);

        $rewards = ReferralReward::where('referral_id', $referral->id)->get();
        $this->assertCount(2, $rewards);

        $ledger = PointLedger::all();
        $this->assertCount(2, $ledger);
    }

    public function test_rejects_unknown_referral_code(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid referral code.');

        $this->service->attributeByCode($this->referred, 'nonexistent');
    }

    public function test_rejects_self_referral(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Self-referral is not allowed.');

        $this->service->attributeByCode($this->referrer, 'john');
    }

    public function test_rejects_duplicate_referral(): void
    {
        $this->service->attributeByCode($this->referred, 'john');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate referral is not allowed.');

        $this->service->attributeByCode($this->referred, 'john');
    }

    public function test_rejects_cross_organization_referral(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-organization referral is not allowed.');

        $this->service->attributeByCode($otherUser, 'john');
    }

    public function test_rejects_cross_organization_when_explicit_org_id_matches_referrer(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $orgA->id, 'referral_code' => 'anna']);
        $referred = User::factory()->create(['organization_id' => $orgB->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-organization referral is not allowed.');

        $this->service->attributeByCode($referred, 'anna', organizationId: $orgA->id);
    }

    public function test_rejects_cross_organization_when_explicit_org_id_matches_referred(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $referrer = User::factory()->create(['organization_id' => $orgA->id, 'referral_code' => 'anna']);
        $referred = User::factory()->create(['organization_id' => $orgB->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-organization referral is not allowed.');

        $this->service->attributeByCode($referred, 'anna', organizationId: $orgB->id);
    }

    public function test_rejects_direct_circular_referral(): void
    {
        $this->service->attributeByCode($this->referred, 'john');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular referral is not allowed.');

        $this->service->attributeByCode($this->referrer, $this->referred->referral_code);
    }

    public function test_passes_metadata_to_rewards(): void
    {
        $metadata = ['source' => 'invite_link', 'campaign' => 'spring2026'];

        $referral = $this->service->attributeByCode($this->referred, 'john', metadata: $metadata);

        $rewards = ReferralReward::where('referral_id', $referral->id)->get();
        foreach ($rewards as $reward) {
            $this->assertEquals($metadata, $reward->metadata);
        }
    }
}
