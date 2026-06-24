<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Explorer;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * T126 — Explorer Livewire Tenant Isolation (P0)
 *
 * Vérifie que le composant Explorer isole correctement les données par Organization.
 * Source du risque : T124 audit — Explorer::$organizationId est une propriété publique
 * Livewire. Le composant utilise withoutGlobalScopes() puis filtre sur $this->organizationId.
 * Un tampering de cette propriété côté client contourne l'isolation tenant.
 *
 * Tests verts : comportement normal attendu.
 * Test de tampering : si ce test échoue (assertDontSee échoue), le risque P0 est confirmé.
 */
class T126ExplorerTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Category $category;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'T126 Org A']);
        $this->orgB = Organization::factory()->create(['name' => 'T126 Org B']);
        $this->category = Category::factory()->create();
        $this->userA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->userB = User::factory()->create(['organization_id' => $this->orgB->id]);

        app()->instance('current_organization', $this->orgA);
    }

    // -------------------------------------------------------------------------
    // Comportement normal — isolation attendue
    // -------------------------------------------------------------------------

    public function test_explorer_shows_services_from_current_organization(): void
    {
        Service::factory()->forUser($this->userA)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_A',
            'status' => 'active',
            'organization_id' => $this->orgA->id,
        ]);

        Livewire::test(Explorer::class)
            ->assertSee('T126_SERVICE_ORG_A');
    }

    public function test_explorer_does_not_show_services_from_other_organization(): void
    {
        Service::factory()->forUser($this->userA)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_A',
            'status' => 'active',
            'organization_id' => $this->orgA->id,
        ]);

        Service::factory()->forUser($this->userB)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_B_HIDDEN',
            'status' => 'active',
            'organization_id' => $this->orgB->id,
        ]);

        Livewire::test(Explorer::class)
            ->assertSee('T126_SERVICE_ORG_A')
            ->assertDontSee('T126_SERVICE_ORG_B_HIDDEN');
    }

    public function test_explorer_requests_tab_does_not_show_requests_from_other_organization(): void
    {
        ServiceRequest::factory()->for($this->userA)->for($this->category)->create([
            'title' => 'T126_REQUEST_ORG_A',
            'status' => 'open',
            'organization_id' => $this->orgA->id,
        ]);

        ServiceRequest::factory()->for($this->userB)->for($this->category)->create([
            'title' => 'T126_REQUEST_ORG_B_HIDDEN',
            'status' => 'open',
            'organization_id' => $this->orgB->id,
        ]);

        Livewire::test(Explorer::class)
            ->call('switchTab', 'requests')
            ->assertSee('T126_REQUEST_ORG_A')
            ->assertDontSee('T126_REQUEST_ORG_B_HIDDEN');
    }

    public function test_explorer_mount_initializes_organization_id_from_current_organization(): void
    {
        $component = Livewire::test(Explorer::class);

        $component->assertSet('orgId', $this->orgA->id);
    }

    // -------------------------------------------------------------------------
    // Test de tampering — P0 — Livewire #[Locked] attribute
    //
    // $orgId est protégé par #[Locked] (Livewire 4) :
    // - figé au mount() depuis currentOrganization()
    // - impossible à modifier côté client (exception levée)
    // - préservé dans le snapshot Livewire à travers les appels AJAX
    //
    // Ce test vérifie que la protection #[Locked] empêche bien
    // le tampering côté client.
    // -------------------------------------------------------------------------

    public function test_explorer_locked_orgId_prevents_client_side_tampering(): void
    {
        Service::factory()->forUser($this->userB)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_B_TAMPERING_TARGET',
            'status' => 'active',
            'organization_id' => $this->orgB->id,
        ]);

        $component = Livewire::test(Explorer::class);

        // Tentative de tampering → #[Locked] lève une exception
        try {
            $component->set('orgId', $this->orgB->id);
            $this->fail('CannotUpdateLockedPropertyException should have been thrown');
        } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException $e) {
            $this->assertStringContainsString('orgId', $e->getMessage());
        }

        // orgId reste inchangé et l'org B n'est pas exposée
        $component->assertSet('orgId', $this->orgA->id)
            ->assertDontSee('T126_SERVICE_ORG_B_TAMPERING_TARGET');
    }
}
