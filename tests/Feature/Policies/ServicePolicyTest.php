<?php

namespace Tests\Feature\Policies;

use App\Models\Category;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

class ServicePolicyTest extends TestCase
{
    public function test_owner_can_update_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create();
        $this->assertTrue($user->can('update', $service));
    }

    public function test_non_owner_cannot_update_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = Service::factory()->forUser($owner)->create();
        $this->assertFalse($other->can('update', $service));
    }

    public function test_owner_can_delete_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create();
        $this->assertTrue($user->can('delete', $service));
    }

    public function test_non_owner_cannot_delete_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = Service::factory()->forUser($owner)->create();
        $this->assertFalse($other->can('delete', $service));
    }

    public function test_owner_cannot_update_service_with_active_transaction(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create();
        $buyer = User::factory()->create(['points_balance' => 100]);
        $service->transactions()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $user->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);
        $this->assertFalse($user->can('update', $service));
    }

    public function test_owner_cannot_delete_service_with_active_transaction(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create();
        $buyer = User::factory()->create(['points_balance' => 100]);
        $service->transactions()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $user->id,
            'points_proposed' => 50,
            'status' => 'accepted',
        ]);
        $this->assertFalse($user->can('delete', $service));
    }
}
