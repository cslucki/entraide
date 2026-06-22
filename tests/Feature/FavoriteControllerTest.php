<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Service;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class FavoriteControllerTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_user_can_favorite_service(): void
    {
        $user = $this->orgUser();
        $owner = $this->orgUser();
        $service = Service::factory()->forUser($owner)->create(['organization_id' => $this->testOrganization->id]);

        $response = $this->actingAs($user)->post(route('favorites.toggle', $service));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_user_can_unfavorite_service(): void
    {
        $user = $this->orgUser();
        $owner = $this->orgUser();
        $service = Service::factory()->forUser($owner)->create(['organization_id' => $this->testOrganization->id]);

        Favorite::create(['user_id' => $user->id, 'service_id' => $service->id]);

        $response = $this->actingAs($user)->post(route('favorites.toggle', $service));

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_favorite_page_shows_user_favorites(): void
    {
        $user = $this->orgUser();
        $otherUser = $this->orgUser();
        $service = Service::factory()->forUser($otherUser)->create(['organization_id' => $this->testOrganization->id]);

        Favorite::create(['user_id' => $user->id, 'service_id' => $service->id]);

        $response = $this->actingAs($user)->get(route('favorites.index'));
        $response->assertOk();
        $response->assertSee($service->title);
    }
}
