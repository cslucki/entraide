<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class MessagePolicyTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    public function test_buyer_can_view_transaction_messages(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('view-transaction', $transaction));
    }

    public function test_seller_can_view_transaction_messages(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($seller->can('view-transaction', $transaction));
    }

    public function test_non_participant_cannot_view_messages(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $other = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertFalse($other->can('view-transaction', $transaction));
    }

    public function test_buyer_can_send_message_on_active_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertTrue($buyer->can('store-message', $transaction));
    }

    public function test_cannot_send_message_on_completed_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->completed()->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('store-message', $transaction));
    }

    public function test_cannot_send_message_on_refused_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->refused()->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('store-message', $transaction));
    }

    public function test_cannot_send_message_on_cancelled_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->cancelled()->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('store-message', $transaction));
    }

    public function test_cross_organization_denied(): void
    {
        $otherOrg = Organization::factory()->create();
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $otherOrg->id]);
        $this->assertFalse($buyer->can('view-transaction', $transaction));
        $this->assertFalse($buyer->can('store-message', $transaction));
    }

    public function test_no_organization_resolved_denied(): void
    {
        app()->forgetInstance('current_organization');
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['community_id' => $this->org->id]);
        $this->assertFalse($buyer->can('view-transaction', $transaction));
    }
}
