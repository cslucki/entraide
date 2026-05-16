<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }
    public function test_buyer_can_create_transaction_on_service(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create(['points_balance' => 200]);
        $service = Service::factory()->forUser($seller)->create();

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertSessionHasNoErrors('error');
        $this->assertDatabaseHas('transactions', [
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'pending',
        ]);
    }

    public function test_cannot_create_transaction_with_insufficient_points(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create(['points_balance' => 10]);
        $service = Service::factory()->forUser($seller)->create();

        $response = $this->actingAs($buyer)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertSessionHas('error');
    }

    public function test_cannot_create_transaction_with_yourself(): void
    {
        $user = User::factory()->create(['points_balance' => 200]);
        $service = Service::factory()->forUser($user)->create();

        $response = $this->actingAs($user)->post(route('transactions.store'), [
            'service_id' => $service->id,
            'points_proposed' => 50,
        ]);

        $response->assertSessionHas('error');
    }

    public function test_seller_can_approve_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($seller)->patch(route('transactions.approve', $transaction));
        $response->assertRedirect();
        $this->assertEquals('accepted', $transaction->fresh()->status);
    }

    public function test_seller_can_refuse_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($seller)->patch(route('transactions.refuse', $transaction));
        $this->assertEquals('refused', $transaction->fresh()->status);
    }

    public function test_buyer_can_complete_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'accepted',
            'points_agreed' => 50,
        ]);

        $response = $this->actingAs($buyer)->patch(route('transactions.complete', $transaction));
        $this->assertEquals('buyer_done', $transaction->fresh()->status);
    }

    public function test_cannot_approve_if_not_seller(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create();

        $transaction = Transaction::create([
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($buyer)->patch(route('transactions.approve', $transaction));
        $response->assertForbidden();
    }
}
