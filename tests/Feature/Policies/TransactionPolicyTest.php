<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class TransactionPolicyTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    public function test_buyer_can_view_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('view', $transaction));
    }

    public function test_seller_can_view_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($seller->can('view', $transaction));
    }

    public function test_non_participant_cannot_view_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertFalse($other->can('view', $transaction));
    }

    public function test_seller_can_approve_pending_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($seller->can('approve', $transaction));
    }

    public function test_buyer_cannot_approve_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('approve', $transaction));
    }

    public function test_seller_cannot_approve_accepted_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create(['community_id' => $this->org->id]);
        $this->assertFalse($seller->can('approve', $transaction));
    }

    public function test_seller_can_refuse_pending_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($seller->can('refuse', $transaction));
    }

    public function test_participants_can_adjust_pending_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('adjust', $transaction));
        $this->assertTrue($seller->can('adjust', $transaction));
    }

    public function test_non_participants_cannot_adjust_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertFalse($other->can('adjust', $transaction));
    }

    public function test_participants_can_cancel_pending_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('cancel', $transaction));
        $this->assertTrue($seller->can('cancel', $transaction));
    }

    public function test_participants_can_cancel_accepted_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('cancel', $transaction));
    }

    public function test_cannot_cancel_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('cancel', $transaction));
    }

    public function test_buyer_can_complete_accepted_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('complete', $transaction));
    }

    public function test_seller_cannot_complete_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->accepted()->create(['community_id' => $this->org->id]);
        $this->assertFalse($seller->can('complete', $transaction));
    }

    public function test_seller_can_confirm_buyer_done(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->buyerDone()->create(['community_id' => $this->org->id]);
        $this->assertTrue($seller->can('confirm', $transaction));
    }

    public function test_buyer_cannot_confirm(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->buyerDone()->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('confirm', $transaction));
    }

    public function test_seller_can_contest_buyer_done(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->buyerDone()->create(['community_id' => $this->org->id]);
        $this->assertTrue($seller->can('contest', $transaction));
    }

    public function test_cross_organization_denied(): void
    {
        $otherOrg = Organization::factory()->create();
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $otherOrg->id]);
        $this->assertFalse($buyer->can('view', $transaction));
        $this->assertFalse($seller->can('approve', $transaction));
    }

    public function test_no_organization_resolved_denied(): void
    {
        app()->forgetInstance('current_organization');
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('view', $transaction));
    }
}
