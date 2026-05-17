<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    private Community $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Community::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->org);
    }

    public function test_store_creates_pending_transaction(): void
    {
        $buyer = User::factory()->create(['points_balance' => 300]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create(['points_cost' => 100, 'status' => 'active', 'community_id' => $this->org->id]);

        $token = $buyer->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/transactions', [
                'service_id' => $service->id,
                'points_proposed' => 100,
            ])
            ->assertCreated()
            ->assertJsonFragment(['status' => 'pending', 'points_proposed' => 100]);

        $this->assertDatabaseHas('transactions', [
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'pending',
        ]);
    }

    public function test_store_rejected_when_balance_insufficient(): void
    {
        $buyer = User::factory()->create(['points_balance' => 50]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create(['status' => 'active', 'community_id' => $this->org->id]);

        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson('/api/transactions', [
                'service_id' => $service->id,
                'points_proposed' => 200,
            ])
            ->assertUnprocessable();
    }

    public function test_store_rejected_when_duplicate_pending_exists(): void
    {
        $buyer = User::factory()->create(['points_balance' => 500]);
        $seller = User::factory()->create();
        $service = Service::factory()->forUser($seller)->create(['status' => 'active', 'community_id' => $this->org->id]);

        Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'service_id' => $service->id,
            'community_id' => $this->org->id,
            'status' => 'pending',
        ]);

        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson('/api/transactions', [
                'service_id' => $service->id,
                'points_proposed' => 100,
            ])
            ->assertUnprocessable();
    }

    public function test_approve_transitions_to_accepted(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create(['points_balance' => 300]);
        $service = Service::factory()->forUser($seller)->create(['status' => 'active', 'community_id' => $this->org->id]);

        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'service_id' => $service->id,
            'community_id' => $this->org->id,
            'status' => 'pending',
            'points_proposed' => 100,
        ]);

        $this->withToken($seller->createToken('api')->plainTextToken)
            ->postJson("/api/transactions/{$tx->id}/approve")
            ->assertOk()
            ->assertJsonFragment(['status' => 'accepted']);
    }

    public function test_buyer_cannot_approve(): void
    {
        $buyer = User::factory()->create(['points_balance' => 200]);
        $seller = User::factory()->create();
        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'community_id' => $this->org->id,
            'status' => 'pending',
        ]);

        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson("/api/transactions/{$tx->id}/approve")
            ->assertForbidden();
    }

    public function test_refuse_transitions_to_refused(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create(['points_balance' => 300]);
        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'community_id' => $this->org->id,
            'status' => 'pending',
        ]);

        $this->withToken($seller->createToken('api')->plainTextToken)
            ->postJson("/api/transactions/{$tx->id}/refuse")
            ->assertOk()
            ->assertJsonFragment(['status' => 'refused']);
    }

    public function test_cancel_allowed_by_buyer_on_pending(): void
    {
        $buyer = User::factory()->create(['points_balance' => 300]);
        $seller = User::factory()->create();
        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'community_id' => $this->org->id,
            'status' => 'pending',
        ]);

        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson("/api/transactions/{$tx->id}/cancel")
            ->assertOk()
            ->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_full_lifecycle_transfers_points(): void
    {
        $buyer = User::factory()->create(['points_balance' => 300]);
        $seller = User::factory()->create(['points_balance' => 100]);
        $service = Service::factory()->forUser($seller)->create(['status' => 'active', 'community_id' => $this->org->id]);

        $tx = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/transactions', [
                'service_id' => $service->id,
                'points_proposed' => 100,
            ])->json();

        $txId = $tx['id'];

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/transactions/{$txId}/approve")
            ->assertOk();

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/transactions/{$txId}/complete")
            ->assertOk()
            ->assertJsonFragment(['status' => 'buyer_done']);

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/transactions/{$txId}/confirm")
            ->assertOk()
            ->assertJsonFragment(['status' => 'completed']);

        $this->assertEquals(200, $buyer->fresh()->points_balance);
        $this->assertEquals(200, $seller->fresh()->points_balance);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $buyer->id,
            'delta' => -100,
            'reason' => 'exchange_spent',
        ]);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $seller->id,
            'delta' => 100,
            'reason' => 'exchange_earned',
        ]);
    }

    public function test_show_returns_403_for_third_party(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $outsider = User::factory()->create();

        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'community_id' => $this->org->id,
            'status' => 'pending',
        ]);

        $this->withToken($outsider->createToken('api')->plainTextToken)
            ->getJson("/api/transactions/{$tx->id}")
            ->assertForbidden();
    }

    public function test_index_returns_only_user_transactions(): void
    {
        $user = User::factory()->create(['points_balance' => 500]);
        $other = User::factory()->create(['points_balance' => 500]);

        Transaction::factory()->count(2)->create(['buyer_id' => $user->id, 'seller_id' => $other->id, 'community_id' => $this->org->id]);
        Transaction::factory()->count(3)->create(['buyer_id' => $other->id, 'seller_id' => User::factory()->create()->id, 'community_id' => $this->org->id]);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
}
