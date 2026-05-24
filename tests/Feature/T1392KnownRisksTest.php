<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\Scopes\BelongsToTenantScope;
use App\Models\Service;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Tests\TestCase;

/**
 * T139.2 — Known-Risk Tests (non-bloquants)
 *
 * Ces tests documentent les risques connus de l'état legacy actuel.
 * Ils sont marqués @group tenant-known-risk et sont SKIPPED par défaut.
 *
 * Ils ne doivent PAS bloquer la CI principale.
 * Ils serviront de référence lors des tâches T140.x pour valider la migration.
 *
 * Pour exécuter ces tests :
 *   php artisan test --group=tenant-known-risk
 *
 * Pour activer tous les tests (y compris les risques connus) :
 *   php artisan test --group=tenant-known-risk --no-configuration
 *   (ou retirer le @group pour exécution locale)
 *
 * Risques documentés :
 *   1. BelongsToTenantScope devrait filtrer sur organization_id à terme
 *   2. Loop devrait avoir organization_id à terme
 *   3. current_community devrait disparaître à terme
 *   4. ResolveApiOrganization devrait être organization-first à terme
 *   5. Broadcast channels devraient comparer organization_id à terme
 *   6. Routes /org/{organization} devraient exister en parallèle avant dépréciation /{community}
 *   7. Tests Explorer legacy doivent être nettoyés après stabilisation
 */
class T1392KnownRisksTest extends TestCase
{

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true]);
        $this->userA = User::factory()->create([
            'community_id' => $this->orgA->id,
            'organization_id' => $this->orgA->id,
        ]);

        app()->instance('current_organization', $this->orgA);
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 1: BelongsToTenantScope devrait filtrer sur
    // organization_id (pas community_id)
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_scope_should_filter_by_organization_id(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.1: BelongsToTenantScope filtre actuellement sur community_id. '.
            'Objectif : remplacer WHERE community_id = ? par WHERE organization_id = ?. '.
            'Activation : après migration DB complète et validation HasOrganizationId sync.'
        );

        $scope = new BelongsToTenantScope;
        $query = Service::query();
        $scope->apply($query, new Service);

        $this->assertStringContainsString(
            'organization_id',
            $query->toSql(),
            'BelongsToTenantScope devrait filtrer sur organization_id à terme'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 2: Loop devrait avoir organization_id
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_loop_should_have_organization_id(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.2: Loop na pas organization_id. '.
            'Objectif : ajouter organization_id nullable + backfill + HasOrganizationId trait + factory. '.
            'Impact : LoopService, LoopMessageService, tests Loop doivent être migrés.'
        );

        $loop = Loop::factory()->create([
            'community_id' => $this->orgA->id,
        ]);

        $this->assertNotNull(
            $loop->organization_id,
            'Loop devrait avoir organization_id'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 3: current_community devrait disparaître
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_current_community_should_be_removed(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.3: current_community est un fallback legacy. '.
            'Objectif : supprimer current_community des bindings après migration des callers. '.
            'CurrentOrganization::get() ne doit plus fallback sur current_community.'
        );

        $this->assertNull(
            CurrentOrganization::get(),
            'Ne devrait pas retourner de résultat via current_community fallback'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 4: ResolveApiOrganization devrait être
    // organization-first (pas user->community_id)
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_resolve_api_should_use_organization_id(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.5: ResolveApiOrganization utilise $user->community_id. '.
            'Objectif : utiliser organization_id comme source de vérité.'
        );

        $this->assertNotNull($this->userA->organization_id);

        // Après migration, le middleware devrait utiliser organization_id
        $this->assertEquals(
            $this->userA->organization_id,
            $this->userA->community_id,
            'organization_id devrait être la source unique'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 5: Broadcast channels devraient comparer
    // organization_id
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_broadcast_should_compare_organization_id(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.5: routes/channels.php compare $loop->community_id !== $user->community_id. '.
            'Objectif : remplacer par comparaison organization_id après migration.'
        );

        $loop = Loop::factory()->create([
            'community_id' => $this->orgA->id,
        ]);

        $this->assertEquals(
            $this->userA->organization_id,
            $loop->community_id,
            'Broadcast devrait comparer organization_id à terme'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 6: Routes /org/{organization} en parallèle
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_org_routes_should_exist_in_parallel(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.4: Les routes /org/{organization} nexistent pas encore. '.
            'Objectif : créer le groupe /org/{organization} (middleware organization déjà prêt). '.
            'Puis redirect /{community} → /org/{organization} temporairement, puis dépréciation.'
        );

        $response = $this->get("/org/{$this->orgA->slug}/");
        $response->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 7: ExplorerTest legacy cleanup
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_explorer_test_legacy_cleanup(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.6: tests/Feature/Livewire/ExplorerTest.php contient 9 tests '.
            'sans tenant scoping (faux positifs). '.
            'Objectif : cleanup après stabilisation des tests Organization-native.'
        );

        $this->assertTrue(true, 'Placeholder — remplacer par vérification réelle dans T140.6');
    }
}
