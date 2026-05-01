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

}
