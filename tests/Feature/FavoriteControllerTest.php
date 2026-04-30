<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Models\Favorite;
use Tests\TestCase;

class FavoriteControllerTest extends TestCase
{
    public function test_user_can_favorite_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser(User::factory()->create())->create();

        $response = $this->actingAs($user)->post(route('favorites.toggle', $service));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_user_can_unfavorite_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->forUser(User::factory()->create())->create();

        Favorite::create(['user_id' => $user->id, 'service_id' => $service->id]);

        $response = $this->actingAs($user)->post(route('favorites.toggle', $service));

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_favorite_page_shows_user_favorites(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $service = Service::factory()->forUser($otherUser)->create();

        Favorite::create(['user_id' => $user->id, 'service_id' => $service->id]);

        $response = $this->actingAs($user)->get(route('favorites.index'));
        $response->assertOk();
        $response->assertSee($service->title);
    }
}
