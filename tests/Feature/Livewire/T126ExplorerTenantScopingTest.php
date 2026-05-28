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
    // Test de tampering — P0 — risque Livewire public property
    //
    // Ce test vérifie si un attaquant peut voir les données d'une autre
    // Organization en forçant la propriété publique `organizationId` via Livewire.
    //
    // Si ce test ÉCHOUE (assertDontSee échoue), le risque P0 est CONFIRMÉ :
    // le composant affiche des données cross-org après tampering.
    //
    // Patch recommandé (non appliqué ici, à valider COCKPIT) :
    // - Rendre organizationId protected ou computed
    // - Ou recomputer organizationId côté serveur dans chaque render()
    //   avec une vérification : $this->organizationId = currentOrganization()?->id;
    // -------------------------------------------------------------------------

    public function test_explorer_tampering_organization_id_does_not_expose_cross_org_services(): void
    {
        Service::factory()->forUser($this->userB)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_B_TAMPERING_TARGET',
            'status' => 'active',
            'organization_id' => $this->orgB->id,
        ]);

        // Tampering: forcer orgId à l'ID de l'org B alors que current_org = org A
        Livewire::test(Explorer::class)
            ->set('orgId', $this->orgB->id)
            ->assertDontSee('T126_SERVICE_ORG_B_TAMPERING_TARGET');
    }

    public function test_explorer_tampering_organization_id_does_not_expose_cross_org_requests(): void
    {
        ServiceRequest::factory()->for($this->userB)->for($this->category)->create([
            'title' => 'T126_REQUEST_ORG_B_TAMPERING_TARGET',
            'status' => 'open',
            'organization_id' => $this->orgB->id,
        ]);

        Livewire::test(Explorer::class)
            ->call('switchTab', 'requests')
            ->set('orgId', $this->orgB->id)
            ->assertDontSee('T126_REQUEST_ORG_B_TAMPERING_TARGET');
    }
}
