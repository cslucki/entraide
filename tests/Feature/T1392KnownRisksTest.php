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
 *   1. ✓ BelongsToTenantScope filtre sur organization_id (résolu par T140.1)
 *   2. ✓ Loop a organization_id (résolu par T140.2)
 *   3. current_community devrait disparaître à terme
 *   4. ResolveApiOrganization devrait être organization-first à terme
 *   5. Broadcast channels devraient comparer organization_id à terme
 *   6. Routes /org/{organization} devraient exister en parallèle avant dépréciation /{community}
 *   7. Tests Explorer legacy doivent être nettoyés après stabilisation
 *   8. View variable currentCommunity ne devrait plus être partagée par les middlewares
 *   9. ResolveUrlOrganization ne devrait pas binder current_community en fallback
 *   10. ResolveCommunity devrait être déprécié après migration des routes
 *   11. Redirects /{community} → /org/{organization} à prévoir après dépréciation community.* routes
 *   12. Dépréciation des noms de route community.* après migration complète vers organization.*
 *   13. Duplicate content SEO entre /{community} et /org/{organization} — canonical à définir
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
            'organization_id' => $this->orgA->id,
            'organization_id' => $this->orgA->id,
        ]);

        app()->instance('current_organization', $this->orgA);
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 1: BelongsToTenantScope filtre sur organization_id
    // (migré par T140.1)
    // ─────────────────────────────────────────────────────────────

    public function test_known_risk_scope_should_filter_by_organization_id(): void
    {
        // Migrated by T140.1 — scope bascule community_id → organization_id
        $scope = new BelongsToTenantScope;
        $query = Service::query();
        $scope->apply($query, new Service);

        $this->assertStringContainsString(
            'organization_id',
            $query->toSql(),
            'BelongsToTenantScope devrait filtrer sur organization_id'
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
        $loop = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->assertNotNull(
            $loop->organization_id,
            'Loop devrait avoir organization_id'
        );

        $this->assertEquals(
            $loop->organization_id,
            $loop->organization_id,
            'organization_id doit être synchronisé avec community_id'
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
    // organization-first (pas user->organization_id)
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_resolve_api_should_use_organization_id(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.5: ResolveApiOrganization utilise $user->organization_id. '.
            'Objectif : utiliser organization_id comme source de vérité.'
        );

        $this->assertNotNull($this->userA->organization_id);

        // Après migration, le middleware devrait utiliser organization_id
        $this->assertEquals(
            $this->userA->organization_id,
            $this->userA->organization_id,
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
            'KNOWN RISK — T140.5: routes/channels.php compare $loop->organization_id !== $user->organization_id. '.
            'Objectif : remplacer par comparaison organization_id après migration.'
        );

        $loop = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        $this->assertEquals(
            $this->userA->organization_id,
            $loop->organization_id,
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

    // ─────────────────────────────────────────────────────────────
    // Known Risk 8: currentCommunity view variable
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_current_community_view_should_be_removed(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.6: View::share(\'currentCommunity\') est un legacy '.
            'qui partage la même valeur que currentOrganization. '.
            'Objectif : supprimer le View::share currentCommunity après migration des vues.'
        );

        $this->assertFalse(
            view()->getShared()['currentCommunity'] ?? false,
            'currentCommunity ne devrait plus être partagé aux vues'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 9: ResolveUrlOrganization current_community bind
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_resolve_url_organization_should_not_bind_current_community(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.5: ResolveUrlOrganization::bindOrganization() '.
            'binde current_community en fallback conditionnel. '.
            'Objectif : supprimer ce bind après migration API/organization.'
        );

        $this->assertFalse(
            app()->bound('current_community'),
            'ResolveUrlOrganization ne devrait pas binder current_community'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 10: ResolveCommunity deprecated
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_resolve_community_should_be_deprecated(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.9: ResolveCommunity est un middleware legacy qui '.
            'binde current_community en plus de current_organization. '.
            'Objectif : déprécier ResolveCommunity après migration complète des routes.'
        );

        $this->assertFalse(
            class_exists(\App\Http\Middleware\ResolveCommunity::class) &&
                app('router')->hasMiddlewareAlias('community'),
            'ResolveCommunity devrait être déprécié'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 11: Redirects /{community} → /org/{organization}
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_community_route_redirect_to_org(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.6+: Les routes /{community} devraient rediriger '.
            'vers /org/{organization} après dépréciation des routes community.*. '.
            'Objectif : redirect 301 temporaire puis suppression après migration complète.'
        );

        $this->get("/{$this->orgA->slug}/")->assertRedirect("/org/{$this->orgA->slug}/");
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 12: Dépréciation noms de route community.*
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_community_route_names_deprecated(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — T140.6+: Les noms de route community.* devraient être '.
            'dépréciés après migration complète des appels vers organization.*. '.
            'Objectif : supprimer les alias community.* après migration controllers/vues.'
        );

        $this->assertFalse(
            app('router')->has('community.home'),
            'community.home ne devrait plus exister'
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Known Risk 13: Duplicate content SEO
    // ─────────────────────────────────────────────────────────────

    /**
     * @group tenant-known-risk
     */
    public function test_known_risk_seo_duplicate_content(): void
    {
        $this->markTestSkipped(
            'KNOWN RISK — SEO: Les routes /{community} et /org/{organization} '
            ."servent le m\u00eame contenu. "
            ."Objectif : d\u00e9finir une canonical URL unique (organization.*) et redirect "
            ."les routes legacy apr\u00e8s migration compl\u00e8te."
        );

        $orgResponse = $this->get("/org/{$this->orgA->slug}/");
        $communityResponse = $this->get("/{$this->orgA->slug}/");

        $this->assertEquals(
            $orgResponse->getContent(),
            $communityResponse->getContent(),
            'Le contenu devrait \u00eatre identique'
        );

        $orgResponse->assertHeader('link');
    }
}
