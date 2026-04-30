<?php

namespace Tests\Feature\Policies;

use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class ReviewPolicyTest extends TestCase
{
    public function test_buyer_can_review_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create();
        $this->assertTrue($buyer->can('create', $transaction));
    }

    public function test_seller_can_review_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create();
        $this->assertTrue($seller->can('create', $transaction));
    }

    public function test_cannot_review_non_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create();
        $this->assertFalse($buyer->can('create', $transaction));
    }

    public function test_non_participant_cannot_review(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create();
        $this->assertFalse($other->can('create', $transaction));
    }

    public function test_cannot_review_twice(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create();
        Review::create([
            'transaction_id' => $transaction->id,
            'reviewer_id' => $buyer->id,
            'reviewed_id' => $seller->id,
            'rating' => 5,
        ]);
        $this->assertFalse($buyer->can('create', $transaction));
    }
}
