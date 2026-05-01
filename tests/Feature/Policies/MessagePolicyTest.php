<?php

namespace Tests\Feature\Policies;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class MessagePolicyTest extends TestCase
{
    public function test_buyer_can_view_transaction_messages(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create();
        $this->assertTrue($buyer->can('view-transaction', $transaction));
    }

    public function test_seller_can_view_transaction_messages(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create();
        $this->assertTrue($seller->can('view-transaction', $transaction));
    }

    public function test_non_participant_cannot_view_messages(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create();
        $this->assertFalse($other->can('view-transaction', $transaction));
    }

    public function test_buyer_can_send_message_on_active_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create();
        $this->assertTrue($buyer->can('store-message', $transaction));
    }

    public function test_cannot_send_message_on_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create();
        $this->assertFalse($buyer->can('store-message', $transaction));
    }

    public function test_cannot_send_message_on_refused_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->refused()->create();
        $this->assertFalse($buyer->can('store-message', $transaction));
    }

    public function test_cannot_send_message_on_cancelled_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->cancelled()->create();
        $this->assertFalse($buyer->can('store-message', $transaction));
    }
}
