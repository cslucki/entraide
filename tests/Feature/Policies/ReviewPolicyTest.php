<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class ReviewPolicyTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    public function test_buyer_can_review_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertTrue($buyer->can('create-review', $transaction));
    }

    public function test_seller_can_review_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertTrue($seller->can('create-review', $transaction));
    }

    public function test_cannot_review_non_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }

    public function test_non_participant_cannot_review(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($other->can('create-review', $transaction));
    }

    public function test_cannot_review_twice(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        Review::create([
            'transaction_id' => $transaction->id,
            'reviewer_id' => $buyer->id,
            'reviewed_id' => $seller->id,
            'rating' => 5,
        ]);
        $this->assertFalse($buyer->can('create', $transaction));
    }

    public function test_cross_organization_denied(): void
    {
        $otherOrg = Organization::factory()->create();
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $otherOrg->id]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }

    public function test_no_organization_resolved_denied(): void
    {
        app()->forgetInstance('current_organization');
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($buyer->can('create-review', $transaction));
    }
}
