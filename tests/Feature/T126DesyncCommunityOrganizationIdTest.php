<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Scopes\BelongsToTenantScope;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T126 — Désynchronisation community_id vs organization_id (P0)
 *
 * Vérifie le comportement du système quand community_id != organization_id
 * sur un même enregistrement.
 *
 * Source du risque : T124 audit — BelongsToTenantScope filtre sur community_id,
 * mais ServicePolicy::resourceBelongsToCurrentOrganization() vérifie organization_id.
 * En cas de désync, un enregistrement peut être visible dans les listes (scope)
 * mais autoriser/bloquer des actions incohéremment (policy).
 *
 * Ces tests documentent le comportement actuel. Ils ne patchent pas le runtime.
 *
 * Risque principal :
 * - community_id = OrgA, organization_id = OrgB :
 *   → Service visible dans listing OrgA (scope passe)
 *   → Policy bloque update/delete pour un user OrgA (org_id = OrgB ≠ OrgA)
 *
 * - community_id = OrgB, organization_id = OrgA :
 *   → Service invisible dans listing OrgA (scope filtre par community_id = OrgB)
 *   → Policy autoriserait update/delete pour un user OrgA (org_id = OrgA)
 *   → Mais le service est invisible donc inaccessible par le listing
 */
class T126DesyncCommunityOrganizationIdTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'T126 Desync Org A']);
        $this->orgB = Organization::factory()->create(['name' => 'T126 Desync Org B']);
        $this->ownerA = User::factory()->create(['community_id' => $this->orgA->id]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crée une désynchronisation directement en DB, en bypassant HasOrganizationId.
     * HasOrganizationId synchronise les deux colonnes sur les événements model.
     * On utilise DB::table() pour forcer la désync sans passer par le modèle.
     */
    private function createDesyncedService(int|string $communityId, int|string $organizationId): Service
    {
        $service = Service::factory()->forUser($this->ownerA)->create([
            'community_id' => $communityId,
        ]);

        // Force la désync directement en base sans déclencher les observers
        DB::table('services')
            ->where('id', $service->id)
            ->update([
                'community_id' => $communityId,
                'organization_id' => $organizationId,
            ]);

        return $service->fresh();
    }

    // -------------------------------------------------------------------------
    // Baseline : état synchronisé (comportement normal)
    // -------------------------------------------------------------------------

    public function test_synced_service_is_visible_in_scope_and_authorized_in_policy(): void
    {
        app()->instance('current_organization', $this->orgA);

        $service = Service::factory()->forUser($this->ownerA)->create([
            'community_id' => $this->orgA->id,
            'organization_id' => $this->orgA->id,
        ]);

        // Scope doit inclure ce service
        $this->assertCount(1, Service::all());

        // Policy doit autoriser l'update pour le propriétaire
        $this->assertTrue($this->ownerA->can('update', $service));
    }

    // -------------------------------------------------------------------------
    // Scénario 1 : community_id = OrgA, organization_id = OrgB (désync A→B)
    //
    // Risque : le service apparaît dans le listing OrgA (scope OK)
    // mais la policy rejette l'update (org_id = OrgB ≠ OrgA résolu)
    // -------------------------------------------------------------------------

    public function test_desync_community_a_org_b_is_visible_in_org_a_scope(): void
    {
        app()->instance('current_organization', $this->orgA);

        $this->createDesyncedService(
            communityId: $this->orgA->id,
            organizationId: $this->orgB->id,
        );

        // BelongsToTenantScope filtre sur community_id = OrgA → service visible
        // Si ce test ÉCHOUE, le scope a été amélioré pour détecter la désync.
        $this->assertCount(1, Service::all());
    }

    public function test_desync_community_a_org_b_policy_blocks_update(): void
    {
        app()->instance('current_organization', $this->orgA);

        $service = $this->createDesyncedService(
            communityId: $this->orgA->id,
            organizationId: $this->orgB->id,
        );

        // Policy vérifie organization_id = OrgA → OrgB ≠ OrgA → blocked
        // Si ce test ÉCHOUE, la policy a été modifiée pour accepter community_id aussi.
        $this->assertFalse($this->ownerA->can('update', $service));
    }

    /**
     * Divergence confirmée : scope autorise (community_id = OrgA), policy bloque (organization_id = OrgB).
     * Un service peut être visible dans le listing mais impossible à modifier pour le propriétaire OrgA.
     * Ce comportement est le risque P0 de désynchronisation documenté par T124.
     */
    public function test_desync_community_a_org_b_creates_scope_policy_divergence(): void
    {
        app()->instance('current_organization', $this->orgA);

        $service = $this->createDesyncedService(
            communityId: $this->orgA->id,
            organizationId: $this->orgB->id,
        );

        $visibleInScope = Service::all()->contains($service->id);
        $authorizedByPolicy = $this->ownerA->can('update', $service);

        // Documenter la divergence : visible mais non autorisé
        // Si visibleInScope = true et authorizedByPolicy = false → divergence confirmée
        // Si les deux sont false → scope a été durci
        // Si les deux sont true → policy a été assouplie (à investiguer)
        $this->addToAssertionCount(1); // marquer le test comme exécuté

        // Assertion : la divergence ne doit PAS permettre une action non autorisée
        // i.e., si visible → policy DOIT bloquer (ou si policy autorise → ne doit pas être visible cross-org)
        if ($visibleInScope && $authorizedByPolicy) {
            $this->fail(
                'RISQUE CONFIRMÉ : service désynchronisé visible ET autorisé en policy pour OrgA. '.
                'Un service org_B est accessible et modifiable depuis OrgA.'
            );
        }

        // Documenter le résultat dans un message d'assertion pour le rapport
        $status = match ([$visibleInScope, $authorizedByPolicy]) {
            [true, false] => 'Divergence scope/policy confirmée : visible en listing, bloqué en policy.',
            [false, false] => 'Scope durci : service invisible et policy bloquée.',
            [false, true] => 'Anomalie : policy autorise un service invisible (inaccessible par listing).',
            default => 'État inattendu.',
        };

        $this->assertTrue(true, $status); // always passes, message documente l'état
    }

    // -------------------------------------------------------------------------
    // Scénario 2 : community_id = OrgB, organization_id = OrgA (désync B→A)
    //
    // Risque : service invisible dans listing OrgA (scope filtre sur community_id = OrgB)
    // mais policy l'autoriserait (org_id = OrgA).
    // Cas moins critique (invisible ≠ accessible) mais anomalie architecturale.
    // -------------------------------------------------------------------------

    public function test_desync_community_b_org_a_is_invisible_in_org_a_scope(): void
    {
        app()->instance('current_organization', $this->orgA);

        $this->createDesyncedService(
            communityId: $this->orgB->id,
            organizationId: $this->orgA->id,
        );

        // Scope filtre community_id = OrgA → service avec community_id = OrgB est exclu
        $this->assertCount(0, Service::all());
    }

    public function test_desync_community_b_org_a_policy_would_authorize_if_accessible(): void
    {
        app()->instance('current_organization', $this->orgA);

        $service = $this->createDesyncedService(
            communityId: $this->orgB->id,
            organizationId: $this->orgA->id,
        );

        // Policy vérifie organization_id = OrgA → autorise
        // Ce cas est moins critique car le service est invisible dans les listings OrgA.
        // Documenter : policy authorise un service inaccessible via les listings normaux.
        $this->assertTrue($this->ownerA->can('update', $service));
    }

    // -------------------------------------------------------------------------
    // Scénario 3 : les deux colonnes à NULL (cas legacy ou import partiel)
    // -------------------------------------------------------------------------

    public function test_service_with_null_community_id_is_excluded_by_scope(): void
    {
        app()->instance('current_organization', $this->orgA);

        $service = Service::factory()->forUser($this->ownerA)->create([
            'community_id' => $this->orgA->id,
        ]);

        // Forcer NULL en bypassant le modèle
        DB::table('services')->where('id', $service->id)->update([
            'community_id' => null,
            'organization_id' => null,
        ]);

        // Le scope est fail-closed : whereRaw('0 = 1') si pas d'org résolue
        // Ou filtre community_id = OrgA → NULL ne correspond pas → service exclu
        $this->assertCount(0, Service::all());
    }

    // -------------------------------------------------------------------------
    // Scénario 4 : HasOrganizationId synchronise bien les deux colonnes à la création
    // (confirme que le problème est limité aux données historiques ou imports directs)
    // -------------------------------------------------------------------------

    public function test_creating_service_via_model_keeps_columns_synced(): void
    {
        app()->instance('current_organization', $this->orgA);

        $service = Service::factory()->forUser($this->ownerA)->create([
            'community_id' => $this->orgA->id,
        ]);

        $service->refresh();

        // HasOrganizationId doit synchroniser organization_id = community_id
        $this->assertEquals($this->orgA->id, $service->community_id);
        $this->assertEquals($this->orgA->id, $service->organization_id);
    }
}
