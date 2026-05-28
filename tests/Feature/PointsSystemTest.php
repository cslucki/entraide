<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

class PointsSystemTest extends TestCase
{
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->org);
    }

    public function test_new_user_receives_welcome_bonus_on_registration(): void
    {
        $user = User::factory()->create(['points_balance' => 0]);
        $category = Category::factory()->create();

        $this->actingAs($user);
        $service = Service::factory()->forUser($user)->forCategory($category)->create(['organization_id' => $this->org->id]);

        $user->buyerTransactions()->create([
            'buyer_id' => $user->id,
            'seller_id' => $user->id,
            'organization_id' => $this->org->id,
            'points_proposed' => 100,
            'points_agreed' => 100,
            'status' => 'completed',
            'completed_at' => now(),
            'buyer_confirmed_at' => now(),
            'seller_confirmed_at' => now(),
        ]);

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => $user->buyerTransactions()->first()->id,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);

        $user->increment('points_balance', 100);

        $this->assertEquals(100, $user->fresh()->points_balance);
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);
    }

    public function test_exchange_transfers_points_from_buyer_to_seller(): void
    {
        $seller = User::factory()->create(['points_balance' => 100]);
        $buyer = User::factory()->create(['points_balance' => 300]);
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($seller)->forCategory($category)->create(['organization_id' => $this->org->id]);

        $this->actingAs($buyer);

        $transaction = $service->transactions()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'organization_id' => $this->org->id,
            'points_proposed' => 50,
            'points_agreed' => 50,
            'status' => 'accepted',
            'buyer_confirmed_at' => now()->subHour(),
        ]);

        $transaction->update([
            'status' => 'completed',
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);

        PointLedger::create([
            'user_id' => $buyer->id,
            'transaction_id' => $transaction->id,
            'delta' => -50,
            'reason' => 'exchange_spent',
        ]);
        PointLedger::create([
            'user_id' => $seller->id,
            'transaction_id' => $transaction->id,
            'delta' => 50,
            'reason' => 'exchange_earned',
        ]);

        $buyer->decrement('points_balance', 50);
        $seller->increment('points_balance', 50);

        $this->assertEquals(250, $buyer->fresh()->points_balance);
        $this->assertEquals(150, $seller->fresh()->points_balance);
    }

    public function test_point_ledger_is_append_only(): void
    {
        $user = User::factory()->create(['points_balance' => 200]);

        PointLedger::create([
            'user_id' => $user->id,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);
        PointLedger::create([
            'user_id' => $user->id,
            'delta' => -30,
            'reason' => 'exchange_spent',
        ]);
        PointLedger::create([
            'user_id' => $user->id,
            'delta' => 50,
            'reason' => 'exchange_earned',
        ]);

        $total = $user->pointLedger()->sum('delta');
        $this->assertEquals(120, $total);

        $count = $user->pointLedger()->count();
        $this->assertEquals(3, $count);

        $net = 100 - 30 + 50;
        $this->assertEquals(120, $net);
    }

    public function test_adjustment_writes_to_ledger(): void
    {
        $user = User::factory()->create(['points_balance' => 100]);

        PointLedger::create([
            'user_id' => $user->id,
            'delta' => 25,
            'reason' => 'adjustment',
        ]);
        $user->increment('points_balance', 25);

        $this->assertEquals(125, $user->fresh()->points_balance);
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'delta' => 25,
            'reason' => 'adjustment',
        ]);
    }

    public function test_points_reason_enum_values(): void
    {
        $validReasons = ['welcome_bonus', 'exchange_earned', 'exchange_spent', 'adjustment'];
        foreach ($validReasons as $reason) {
            $user = User::factory()->create();
            PointLedger::create([
                'user_id' => $user->id,
                'delta' => 10,
                'reason' => $reason,
            ]);
            $this->assertDatabaseHas('point_ledger', ['reason' => $reason]);
        }
    }
}
