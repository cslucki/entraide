<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

class ServicePolicyTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    public function test_owner_can_update_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create(['community_id' => $this->org->id]);
        $this->assertTrue($user->can('update', $service));
    }

    public function test_non_owner_cannot_update_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = Service::factory()->forUser($owner)->create(['community_id' => $this->org->id]);
        $this->assertFalse($other->can('update', $service));
    }

    public function test_owner_can_delete_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create(['community_id' => $this->org->id]);
        $this->assertTrue($user->can('delete', $service));
    }

    public function test_non_owner_cannot_delete_service(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = Service::factory()->forUser($owner)->create(['community_id' => $this->org->id]);
        $this->assertFalse($other->can('delete', $service));
    }

    public function test_cross_organization_denied(): void
    {
        $otherOrg = Organization::factory()->create();
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create(['community_id' => $otherOrg->id]);
        $this->assertFalse($user->can('update', $service));
        $this->assertFalse($user->can('delete', $service));
    }

    public function test_no_organization_resolved_denied(): void
    {
        app()->forgetInstance('current_organization');
        $user = User::factory()->create();
        $service = Service::factory()->forUser($user)->create(['community_id' => $this->org->id]);
        $this->assertFalse($user->can('update', $service));
        $this->assertFalse($user->can('delete', $service));
    }
}
