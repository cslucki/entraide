<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class ServiceControllerTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }
    public function test_anyone_can_view_active_service(): void
    {
        $user = $this->orgUser();
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create(['community_id' => $this->testOrganization->id]);

        $response = $this->get(route('services.show', $service));
        $response->assertOk();
    }

    public function test_paused_service_visible_only_to_owner(): void
    {
        $owner = $this->orgUser();
        $other = $this->orgUser();
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($owner)->forCategory($category)->paused()->create(['community_id' => $this->testOrganization->id]);

        $response = $this->actingAs($owner)->get(route('services.show', $service));
        $response->assertOk();

        $response = $this->actingAs($other)->get(route('services.show', $service));
        $response->assertNotFound();
    }

    public function test_owner_can_create_service(): void
    {
        $user = $this->orgUser(['bio' => 'Développeur web freelance.']);
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Test Service',
            'description' => 'A test service description that is long enough to pass the minimum length validation requirement of one hundred characters.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('services', ['title' => 'Test Service', 'user_id' => $user->id]);
    }

    public function test_owner_can_edit_service(): void
    {
        $user = $this->orgUser();
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create(['community_id' => $this->testOrganization->id]);

        $response = $this->actingAs($user)->put(route('services.update', $service), [
            'title' => 'Updated Title',
            'description' => 'Updated description that is long enough to pass the minimum length validation requirement of one hundred characters.',
            'category_id' => $category->id,
            'delivery_mode' => 'both',
            'points_cost' => 80,
            'status' => 'paused',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('services', ['id' => $service->id, 'title' => 'Updated Title', 'status' => 'paused']);
    }

    public function test_non_owner_cannot_edit_service(): void
    {
        $owner = $this->orgUser();
        $other = $this->orgUser();
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($owner)->forCategory($category)->create(['community_id' => $this->testOrganization->id]);

        $response = $this->actingAs($other)->put(route('services.update', $service), [
            'title' => 'Hacked',
            'description' => 'Hacked.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 90,
            'status' => 'active',
        ]);

        $response->assertForbidden();
    }

    public function test_owner_can_delete_service_without_active_transactions(): void
    {
        $user = $this->orgUser();
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($user)->forCategory($category)->create(['community_id' => $this->testOrganization->id]);

        $response = $this->actingAs($user)->delete(route('services.destroy', $service));
        $response->assertRedirect(route('dashboard'));
        $this->assertSoftDeleted($service);
    }

    public function test_cannot_delete_service_with_active_transaction(): void
    {
        $seller = $this->orgUser();
        $buyer = $this->orgUser(['points_balance' => 100]);
        $category = Category::factory()->create();
        $service = Service::factory()->forUser($seller)->forCategory($category)->create(['community_id' => $this->testOrganization->id]);

        Transaction::create([
            'community_id' => $this->testOrganization->id,
            'service_id' => $service->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'points_proposed' => 50,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($seller)->delete(route('services.destroy', $service));
        $response->assertSessionHas('error');
        $this->assertNotSoftDeleted($service->fresh());
    }

    public function test_service_creation_with_tags(): void
    {
        $user = $this->orgUser([
            'bio' => 'Développeur web freelance.',
            'location' => 'Paris',
            'phone' => '0123456789',
        ]);
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Service with tags',
            'description' => 'Description that is long enough to pass the minimum length validation requirement of one hundred characters here.',
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 50,
            'tags' => 'urgent, python, web',
        ]);

        $response->assertRedirect(route('dashboard'));
        $service = Service::withoutGlobalScope(\App\Models\Scopes\BelongsToTenantScope::class)->where('title', 'Service with tags')->first();
        $this->assertEquals(3, $service->tags()->count());
    }
}
