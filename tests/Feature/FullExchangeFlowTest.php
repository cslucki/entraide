<?php

namespace Tests\Feature;

use App\Models\PointLedger;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class FullExchangeFlowTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }
    public function test_complete_exchange_flow(): void
    {
        $buyer = $this->orgUser(['points_balance' => 300]);
        $seller = $this->orgUser(['points_balance' => 100]);

        $service = Service::factory()->forUser($seller)->create([
            'title' => 'Design de logo',
            'points_cost' => 75,
            'organization_id' => $this->testOrganization->id,
        ]);

        $this->actingAs($buyer)
            ->post(route('transactions.store'), [
                'service_id' => $service->id,
                'points_proposed' => 75,
            ])
            ->assertSessionHas('success');

        $transaction = Transaction::where('service_id', $service->id)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('pending', $transaction->status);

        $this->actingAs($seller)
            ->patch(route('transactions.approve', $transaction))
            ->assertRedirect();
        $this->assertEquals('accepted', $transaction->fresh()->status);

        $this->actingAs($buyer)
            ->patch(route('transactions.complete', $transaction))
            ->assertRedirect();
        $this->assertEquals('buyer_done', $transaction->fresh()->status);

        $this->actingAs($seller)
            ->patch(route('transactions.confirm', $transaction))
            ->assertRedirect();
        $this->assertEquals('completed', $transaction->fresh()->status);

        $this->assertEquals(225, $buyer->fresh()->points_balance);
        $this->assertEquals(175, $seller->fresh()->points_balance);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $buyer->id,
            'transaction_id' => $transaction->id,
            'delta' => -75,
            'reason' => 'exchange_spent',
        ]);
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $seller->id,
            'transaction_id' => $transaction->id,
            'delta' => 75,
            'reason' => 'exchange_earned',
        ]);
    }

    public function test_exchange_can_be_refused(): void
    {
        $buyer = $this->orgUser(['points_balance' => 200]);
        $seller = $this->orgUser();
        $service = Service::factory()->forUser($seller)->create(['organization_id' => $this->testOrganization->id]);

        $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 100,
        ]);

        $transaction = Transaction::first();

        $this->actingAs($seller)->patch(route('transactions.refuse', $transaction));
        $this->assertEquals('refused', $transaction->fresh()->status);

        $this->assertEquals(200, $buyer->fresh()->points_balance);
        $this->assertEquals(0, PointLedger::where('transaction_id', $transaction->id)->count());
    }

    public function test_exchange_can_be_cancelled(): void
    {
        $buyer = $this->orgUser(['points_balance' => 200]);
        $seller = $this->orgUser();
        $service = Service::factory()->forUser($seller)->create(['organization_id' => $this->testOrganization->id]);

        $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 100,
        ]);

        $transaction = Transaction::first();

        $this->actingAs($seller)->patch(route('transactions.cancel', $transaction));
        $this->assertEquals('cancelled', $transaction->fresh()->status);

        $this->assertEquals(200, $buyer->fresh()->points_balance);
    }
}
