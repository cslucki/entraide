<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Tests\TestCase;

/**
 * T075.5 — Services / Requests Tenant Safety + Hidden Field Tampering
 *
 * Vérifie que community_id fourni par le client (hidden field tamperé) est ignoré.
 * Seule l'Organization résolue côté serveur est utilisée à la création.
 */
class T0755ServicesRequestsTenantSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Service — hidden field tampering
    // ─────────────────────────────────────────────────────────────

    public function test_service_store_uses_resolved_organization_not_tampered_community_id(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $user = $this->createUser($organizationA);
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->post(route('services.store'), array_merge($this->validServiceData($category), [
                'organization_id' => $organizationB->id,
            ]))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('services', [
            'user_id' => $user->id,
            'organization_id' => $organizationA->id,
        ]);

        $this->assertDatabaseMissing('services', [
            'user_id' => $user->id,
            'organization_id' => $organizationB->id,
        ]);
    }

    public function test_service_store_fails_safe_when_no_organization_resolved(): void
    {
        // Aucune Organization active en base — middleware et controller doivent bloquer.
        $user = User::factory()->create(['organization_id' => null]);

        $this->actingAs($user)
            ->post(route('services.store'), $this->validServiceData(Category::factory()->create()))
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // Request — hidden field tampering
    // ─────────────────────────────────────────────────────────────

    public function test_request_store_uses_resolved_organization_not_tampered_community_id(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $user = $this->createUser($organizationA);
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->post(route('requests.store'), array_merge($this->validRequestData($category), [
                'organization_id' => $organizationB->id,
            ]))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('service_requests', [
            'user_id' => $user->id,
            'organization_id' => $organizationA->id,
        ]);

        $this->assertDatabaseMissing('service_requests', [
            'user_id' => $user->id,
            'organization_id' => $organizationB->id,
        ]);
    }

    public function test_request_store_fails_safe_when_no_organization_resolved(): void
    {
        $user = User::factory()->create(['organization_id' => null]);

        $this->actingAs($user)
            ->post(route('requests.store'), $this->validRequestData(Category::factory()->create()))
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // Route-model binding — cross-Organization access
    // ─────────────────────────────────────────────────────────────

    public function test_service_show_is_scoped_to_resolved_organization(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $user = $this->createUser($organizationB);
        $serviceInOrgB = Service::factory()->forUser($user)->create([
            'organization_id' => $organizationB->id,
        ]);

        // Org A est résolue — service de Org B ne doit pas être accessible.
        $this->get(route('services.show', $serviceInOrgB))
            ->assertNotFound();
    }

    public function test_request_show_is_scoped_to_resolved_organization(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $user = $this->createUser($organizationB);
        $requestInOrgB = ServiceRequest::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationB->id,
        ]);

        // Org A est résolue — request de Org B ne doit pas être accessible.
        $this->get(route('requests.show', $requestInOrgB))
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /** @return array{Organization, Organization} */
    private function createOrganizations(): array
    {
        $organizationA = Organization::factory()->create(['is_active' => true]);
        $organizationB = Organization::factory()->create(['is_active' => true]);

        $organizationA->update(['is_default' => true]);

        return [$organizationA, $organizationB];
    }

    private function createUser(Organization $organization): User
    {
        return User::factory()->create(['organization_id' => $organization->id]);
    }

    private function validServiceData(Category $category): array
    {
        return [
            'title' => 'Service de test pour T075.5',
            'description' => str_repeat('Description longue du service de test pour valider la sécurité tenant. ', 3),
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 50,
        ];
    }

    private function validRequestData(Category $category): array
    {
        return [
            'title' => 'Demande de test pour T075.5',
            'description' => str_repeat('Description longue de la demande de test pour valider la sécurité tenant. ', 3),
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'budget_min' => 10,
        ];
    }
}
