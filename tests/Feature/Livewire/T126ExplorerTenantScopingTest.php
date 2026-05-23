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
 * Source du risque : T124 audit — Explorer::$communityId est une propriété publique
 * Livewire. Le composant utilise withoutGlobalScopes() puis filtre sur $this->communityId.
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
        $this->userA = User::factory()->create(['community_id' => $this->orgA->id]);
        $this->userB = User::factory()->create(['community_id' => $this->orgB->id]);

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
            'community_id' => $this->orgA->id,
        ]);

        Livewire::test(Explorer::class)
            ->assertSee('T126_SERVICE_ORG_A');
    }

    public function test_explorer_does_not_show_services_from_other_organization(): void
    {
        Service::factory()->forUser($this->userA)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_A',
            'status' => 'active',
            'community_id' => $this->orgA->id,
        ]);

        Service::factory()->forUser($this->userB)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_B_HIDDEN',
            'status' => 'active',
            'community_id' => $this->orgB->id,
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
            'community_id' => $this->orgA->id,
        ]);

        ServiceRequest::factory()->for($this->userB)->for($this->category)->create([
            'title' => 'T126_REQUEST_ORG_B_HIDDEN',
            'status' => 'open',
            'community_id' => $this->orgB->id,
        ]);

        Livewire::test(Explorer::class)
            ->call('switchTab', 'requests')
            ->assertSee('T126_REQUEST_ORG_A')
            ->assertDontSee('T126_REQUEST_ORG_B_HIDDEN');
    }

    public function test_explorer_mount_initializes_community_id_from_current_organization(): void
    {
        $component = Livewire::test(Explorer::class);

        $component->assertSet('communityId', $this->orgA->id);
    }

    // -------------------------------------------------------------------------
    // Test de tampering — P0 — risque Livewire public property
    //
    // Ce test vérifie si un attaquant peut voir les données d'une autre
    // Organization en forçant la propriété publique `communityId` via Livewire.
    //
    // Si ce test ÉCHOUE (assertDontSee échoue), le risque P0 est CONFIRMÉ :
    // le composant affiche des données cross-org après tampering.
    //
    // Patch recommandé (non appliqué ici, à valider COCKPIT) :
    // - Rendre communityId protected ou computed
    // - Ou recomputer communityId côté serveur dans chaque render()
    //   avec une vérification : $this->communityId = currentOrganization()?->id;
    // -------------------------------------------------------------------------

    public function test_explorer_tampering_community_id_does_not_expose_cross_org_services(): void
    {
        Service::factory()->forUser($this->userB)->for($this->category)->create([
            'title' => 'T126_SERVICE_ORG_B_TAMPERING_TARGET',
            'status' => 'active',
            'community_id' => $this->orgB->id,
        ]);

        // Tampering: forcer communityId à l'ID de l'org B alors que current_org = org A
        Livewire::test(Explorer::class)
            ->set('communityId', $this->orgB->id)
            ->assertDontSee('T126_SERVICE_ORG_B_TAMPERING_TARGET');
    }

    public function test_explorer_tampering_community_id_does_not_expose_cross_org_requests(): void
    {
        ServiceRequest::factory()->for($this->userB)->for($this->category)->create([
            'title' => 'T126_REQUEST_ORG_B_TAMPERING_TARGET',
            'status' => 'open',
            'community_id' => $this->orgB->id,
        ]);

        Livewire::test(Explorer::class)
            ->call('switchTab', 'requests')
            ->set('communityId', $this->orgB->id)
            ->assertDontSee('T126_REQUEST_ORG_B_TAMPERING_TARGET');
    }
}
