<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Tests\TestCase;

class OrganizationPointScaleTest extends TestCase
{
    public function test_service_create_and_update_apply_current_organization_point_scale(): void
    {
        $organization = $this->organizationWithScale(25, 75, ['is_default' => true]);
        $user = $this->userFor($organization);
        $category = Category::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        $this->actingAs($user)
            ->post(route('services.store'), $this->servicePayload($category, 25))
            ->assertRedirect(route('organization.dashboard.services', [$organization]));

        $this->assertDatabaseHas('services', [
            'organization_id' => $organization->id,
            'points_cost' => 25,
        ]);

        $this->actingAs($user)
            ->post(route('services.store'), $this->servicePayload($category, 24))
            ->assertSessionHasErrors('points_cost');

        $this->actingAs($user)
            ->post(route('services.store'), $this->servicePayload($category, 76))
            ->assertSessionHasErrors('points_cost');

        $service = Service::factory()->forUser($user)->forCategory($category)->create([
            'organization_id' => $organization->id,
            'points_cost' => 50,
        ]);

        $this->actingAs($user)
            ->put(route('services.update', $service), $this->servicePayload($category, 75, ['status' => 'active']))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'points_cost' => 75,
        ]);
    }

    public function test_request_create_and_update_apply_current_organization_point_scale(): void
    {
        $organization = $this->organizationWithScale(30, 90, ['is_default' => true]);
        $user = $this->userFor($organization);
        $category = Category::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        $this->actingAs($user)
            ->post(route('requests.store'), $this->requestPayload($category, 30, 90))
            ->assertRedirect(route('organization.dashboard.requests', [$organization]));

        $this->assertDatabaseHas('service_requests', [
            'organization_id' => $organization->id,
            'budget_min' => 30,
            'budget_max' => 90,
        ]);

        $this->actingAs($user)
            ->post(route('requests.store'), $this->requestPayload($category, 29, 90))
            ->assertSessionHasErrors('budget_min');

        $this->actingAs($user)
            ->post(route('requests.store'), $this->requestPayload($category, 30, 91))
            ->assertSessionHasErrors('budget_max');

        $serviceRequest = ServiceRequest::factory()->forUser($user)->create([
            'organization_id' => $organization->id,
            'category_id' => $category->id,
            'budget_min' => 45,
            'budget_max' => 60,
        ]);

        $this->actingAs($user)
            ->put(route('requests.update', $serviceRequest), $this->requestPayload($category, 30, 90))
            ->assertRedirect(route('organization.dashboard.requests', [$organization]));

        $this->assertDatabaseHas('service_requests', [
            'id' => $serviceRequest->id,
            'budget_min' => 30,
            'budget_max' => 90,
        ]);
    }

    public function test_point_scale_display_uses_current_organization_without_leakage(): void
    {
        app()->setLocale('fr');

        $organizationA = $this->organizationWithScale(20, 70, [
            'name' => 'Organisation Alpha',
            'slug' => 'organisation-alpha',
            'locale' => 'fr',
            'is_default' => true,
        ]);
        $organizationB = $this->organizationWithScale(45, 120, [
            'name' => 'Organisation Beta',
            'slug' => 'organisation-beta',
        ]);
        $user = $this->userFor($organizationA);
        $category = Category::factory()->create(['organization_id' => $organizationA->id]);
        $service = Service::factory()->forUser($user)->forCategory($category)->create(['organization_id' => $organizationA->id]);
        $serviceRequest = ServiceRequest::factory()->forUser($user)->create([
            'organization_id' => $organizationA->id,
            'category_id' => $category->id,
        ]);

        app()->instance('current_organization', $organizationA);

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertOk()
            ->assertSee('Minimum autorisé dans Organisation Alpha : 20 points')
            ->assertSee('Maximum autorisé dans Organisation Alpha : 70 points')
            ->assertDontSee($organizationB->name)
            ->assertDontSee('120 points');

        $this->actingAs($user)
            ->get(route('services.edit', $service))
            ->assertOk()
            ->assertSee('Minimum autorisé dans Organisation Alpha : 20 points')
            ->assertSee('Maximum autorisé dans Organisation Alpha : 70 points');

        $this->actingAs($user)
            ->get(route('requests.create'))
            ->assertOk()
            ->assertSee('Minimum autorisé dans Organisation Alpha : 20 points')
            ->assertSee('Maximum autorisé dans Organisation Alpha : 70 points');

        $this->actingAs($user)
            ->get(route('requests.edit', $serviceRequest))
            ->assertOk()
            ->assertSee('Minimum autorisé dans Organisation Alpha : 20 points')
            ->assertSee('Maximum autorisé dans Organisation Alpha : 70 points');
    }

    public function test_point_scale_display_is_translated_in_english(): void
    {
        app()->setLocale('en');

        $organization = $this->organizationWithScale(15, 65, [
            'name' => 'English Org',
            'is_default' => true,
        ]);
        $user = $this->userFor($organization);
        Category::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        $this->actingAs($user)
            ->get(route('services.create'))
            ->assertOk()
            ->assertSee('1 minute = 1 point')
            ->assertSee('Minimum allowed in English Org: 15 points')
            ->assertSee('Maximum allowed in English Org: 65 points');
    }

    private function organizationWithScale(int $min, int $max, array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'is_active' => true,
            'service_points_min' => $min,
            'service_points_max' => $max,
        ], $overrides));
    }

    private function userFor(Organization $organization): User
    {
        return User::factory()->complete()->create(['organization_id' => $organization->id]);
    }

    private function servicePayload(Category $category, int $points, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Service de test barème',
            'description' => str_repeat('Description suffisante pour valider la proposition avec le barème Organization. ', 3),
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => $points,
        ], $overrides);
    }

    private function requestPayload(Category $category, int $budgetMin, ?int $budgetMax = null): array
    {
        return [
            'title' => 'Demande de test barème',
            'description' => str_repeat('Description suffisante pour valider la demande avec le barème Organization. ', 3),
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'budget_min' => $budgetMin,
            'budget_max' => $budgetMax,
        ];
    }
}
