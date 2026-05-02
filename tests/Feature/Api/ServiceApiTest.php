<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Service;
use App\Models\User;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    public function test_index_returns_active_services_paginated(): void
    {
        Service::factory()->count(3)->create(['status' => 'active']);
        Service::factory()->create(['status' => 'paused']);

        $response = $this->getJson('/api/services');

        $response->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page', 'current_page']);

        $this->assertEquals(3, $response->json('total'));
    }

    public function test_index_filters_by_search_query(): void
    {
        Service::factory()->create(['title' => 'Cours de piano', 'status' => 'active']);
        Service::factory()->create(['title' => 'Jardinage', 'status' => 'active']);

        $response = $this->getJson('/api/services?q=piano');

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Cours de piano', $response->json('data.0.title'));
    }

    public function test_index_filters_by_category(): void
    {
        $category = Category::factory()->create();
        $other = Category::factory()->create();

        Service::factory()->forCategory($category)->create(['status' => 'active']);
        Service::factory()->forCategory($category)->create(['status' => 'active']);
        Service::factory()->forCategory($other)->create(['status' => 'active']);

        $response = $this->getJson("/api/services?category_id={$category->id}");

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_index_filters_by_delivery_mode(): void
    {
        Service::factory()->create(['delivery_mode' => 'remote', 'status' => 'active']);
        Service::factory()->create(['delivery_mode' => 'onsite', 'status' => 'active']);

        $response = $this->getJson('/api/services?delivery_mode=remote');

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_index_filters_by_cost_range(): void
    {
        Service::factory()->create(['points_cost' => 50, 'status' => 'active']);
        Service::factory()->create(['points_cost' => 150, 'status' => 'active']);
        Service::factory()->create(['points_cost' => 300, 'status' => 'active']);

        $response = $this->getJson('/api/services?min_cost=100&max_cost=200');

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_show_returns_active_service_with_relations(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        $response = $this->getJson("/api/services/{$service->id}");

        $response->assertOk()
            ->assertJsonStructure(['id', 'title', 'description', 'points_cost', 'user', 'category']);
    }

    public function test_show_returns_404_for_inactive_service(): void
    {
        $service = Service::factory()->create(['status' => 'paused']);

        $this->getJson("/api/services/{$service->id}")
            ->assertNotFound();
    }

    public function test_show_returns_404_for_soft_deleted_service(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $service->delete();

        $this->getJson("/api/services/{$service->id}")
            ->assertNotFound();
    }
}
