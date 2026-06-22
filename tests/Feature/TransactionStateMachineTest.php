<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class TransactionStateMachineTest extends TestCase
{
    private User $buyer;

    private User $seller;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['points_balance' => 200]);
        $this->seller = User::factory()->create(['points_balance' => 100]);
        $this->transaction = Transaction::factory()
            ->forBuyer($this->buyer)
            ->forSeller($this->seller)
            ->create();
    }

    public function test_initial_state_is_pending(): void
    {
        $this->assertEquals('pending', $this->transaction->status);
    }

    public function test_pending_to_accepted(): void
    {
        $this->transaction->update(['status' => 'accepted', 'points_agreed' => 50]);
        $this->assertEquals('accepted', $this->transaction->fresh()->status);
    }

    public function test_pending_to_refused(): void
    {
        $this->transaction->update(['status' => 'refused']);
        $this->assertEquals('refused', $this->transaction->fresh()->status);
    }

    public function test_pending_to_cancelled(): void
    {
        $this->transaction->update(['status' => 'cancelled']);
        $this->assertEquals('cancelled', $this->transaction->fresh()->status);
    }

    public function test_accepted_to_buyer_done(): void
    {
        $this->transaction->update(['status' => 'accepted']);
        $this->transaction->update(['status' => 'buyer_done', 'buyer_confirmed_at' => now()]);
        $this->assertEquals('buyer_done', $this->transaction->fresh()->status);
    }

    public function test_buyer_done_to_completed(): void
    {
        $this->transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
            'points_agreed' => 50,
        ]);
        $this->transaction->update([
            'status' => 'completed',
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
        $this->assertEquals('completed', $this->transaction->fresh()->status);
    }

    public function test_buyer_done_to_contested_returns_to_accepted(): void
    {
        $this->transaction->update([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
        ]);
        $this->transaction->update(['status' => 'accepted']);
        $this->assertEquals('accepted', $this->transaction->fresh()->status);
    }

    public function test_completed_is_terminal_state(): void
    {
        $this->transaction->update([
            'status' => 'completed',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now(),
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
        $t = $this->transaction->fresh();
        $this->assertEquals('completed', $t->status);
        $this->assertNotNull($t->completed_at);
    }

    public function test_refused_is_terminal_state(): void
    {
        $this->transaction->update(['status' => 'refused']);
        $this->assertEquals('refused', $this->transaction->fresh()->status);
    }

    public function test_cancelled_is_terminal_state(): void
    {
        $this->transaction->update(['status' => 'cancelled']);
        $this->assertEquals('cancelled', $this->transaction->fresh()->status);
    }

    public function test_completed_transaction_has_both_confirmation_timestamps(): void
    {
        $this->transaction->update([
            'status' => 'completed',
            'points_agreed' => 50,
            'buyer_confirmed_at' => now()->subHour(),
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
        $t = $this->transaction->fresh();
        $this->assertNotNull($t->buyer_confirmed_at);
        $this->assertNotNull($t->seller_confirmed_at);
        $this->assertNotNull($t->completed_at);
    }

    public function test_status_labels(): void
    {
        $transaction = Transaction::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
        $this->assertEquals('En attente', $transaction->status_label);

        $transaction->update(['status' => 'accepted']);
        $this->assertEquals('Accepté', $transaction->fresh()->status_label);

        $transaction->update(['status' => 'buyer_done']);
        $this->assertEquals('Terminé (acheteur)', $transaction->fresh()->status_label);

        $transaction->update(['status' => 'completed']);
        $this->assertEquals('Complété', $transaction->fresh()->status_label);

        $transaction->update(['status' => 'refused']);
        $this->assertEquals('Refusé', $transaction->fresh()->status_label);

        $transaction->update(['status' => 'cancelled']);
        $this->assertEquals('Annulé', $transaction->fresh()->status_label);
    }

    public function test_status_colors(): void
    {
        $transaction = Transaction::factory()->forBuyer($this->buyer)->forSeller($this->seller)->create();
        $this->assertEquals('orange', $transaction->status_color);

        $transaction->update(['status' => 'accepted']);
        $this->assertEquals('blue', $transaction->fresh()->status_color);

        $transaction->update(['status' => 'completed']);
        $this->assertEquals('green', $transaction->fresh()->status_color);

        $transaction->update(['status' => 'refused']);
        $this->assertEquals('red', $transaction->fresh()->status_color);
    }
}
