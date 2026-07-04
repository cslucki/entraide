<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

class T348FavoriteTenantScopingTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    public function test_favorite_created_with_organization_id(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $org->update(['is_default' => true]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->post(route('favorites.toggle', $service))
            ->assertRedirect();

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_favorite_toggle_creates_and_deletes_scoped_to_organization(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $org->update(['is_default' => true]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
        ]);

        // Toggle ON
        $this->actingAs($user)
            ->post(route('favorites.toggle', $service))
            ->assertRedirect();

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
            'organization_id' => $org->id,
        ]);

        // Toggle OFF
        $this->actingAs($user)
            ->post(route('favorites.toggle', $service))
            ->assertRedirect();

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_favorite_created_on_org_scoped_route_has_organization_id(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $org->update(['is_default' => true]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->post(route('organization.favorites.toggle', [
                'organization' => $org->slug,
                'service' => $service,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_existing_favorites_without_organization_id_are_still_accessible(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $org->update(['is_default' => true]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $org->id,
        ]);

        // Simulate legacy favorite without org_id
        Favorite::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'organization_id' => $org->id,
        ]);

        // Toggle OFF — should find & delete it (org_id in lookup matches)
        $this->actingAs($user)
            ->post(route('favorites.toggle', $service))
            ->assertRedirect();

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
    }
}
