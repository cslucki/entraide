<?php

namespace Tests\Feature\Policies;

use App\Models\ServiceRequest;
use App\Models\User;
use Tests\TestCase;

class ServiceRequestPolicyTest extends TestCase
{
    public function test_owner_can_delete_request(): void
    {
        $user = User::factory()->create();
        $request = ServiceRequest::factory()->forUser($user)->create();
        $this->assertTrue($user->can('delete', $request));
    }

    public function test_non_owner_cannot_delete_request(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $request = ServiceRequest::factory()->forUser($owner)->create();
        $this->assertFalse($other->can('delete', $request));
    }
}
