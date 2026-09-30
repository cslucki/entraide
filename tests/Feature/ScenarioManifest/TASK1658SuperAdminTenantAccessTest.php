<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1658 — le SuperAdmin plateforme inspecte un tenant SANS en devenir membre.
 *
 * ## Le defaut, et ce qu'il n'etait pas
 *
 * Depuis la sandbox OFSH chargee, le SuperAdmin obtenait **404** sur
 * `/org/<slug>/agenda` et `/org/<slug>/loops`. Ni route absente, ni liage de
 * modele en echec : un `abort(404)` DELIBERE, parce que l'utilisateur
 * authentifie n'appartient pas a l'Organization de l'URL.
 *
 * Le defaut avait DEUX etages, et corriger le premier seul aurait laisse le
 * second invisible :
 *
 * 1. **l'entree** — la garde d'acces au tenant ne connaissait pas le SuperAdmin ;
 * 2. **la vue** — meme entre, un filtre d'APPARTENANCE AUX BOUCLES aurait cache
 *    le contenu du tenant qu'il vient inspecter.
 *
 * ## Ce que ce fichier prouve, et surtout ce qu'il prouve qu'on n'a PAS fait
 *
 * Aucun membership n'est cree, ni d'Organization ni de Boucle. Le cloisonnement
 * de tenant tient : sous l'URL d'un tenant, rien d'un autre n'apparait. Et les
 * gardes d'ACTION — repondre a un evenement, transiger — restent fermees : un
 * SuperAdmin inspecte, il ne participe pas.
 */
class TASK1658SuperAdminTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $organisationDuSuperAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationDuSuperAdmin = Organization::factory()->create(['slug' => 'plateforme']);
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organisationDuSuperAdmin->id,
            'is_admin' => true,
        ]);
    }

    // =====================================================================
    // 1. L'ENTREE : les deux surfaces s'ouvrent
    // =====================================================================

    public function test_le_SuperAdmin_NON_MEMBRE_ouvre_les_Boucles_du_tenant(): void
    {
        $sandbox = $this->sandboxChargee();

        $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.index', ['organization' => $sandbox->slug]))
            ->assertOk();
    }

    public function test_le_SuperAdmin_NON_MEMBRE_ouvre_l_Agenda_du_tenant(): void
    {
        $sandbox = $this->sandboxChargee();

        $this->actingAs($this->superAdmin)
            ->get(route('organization.events.agenda', ['organization' => $sandbox->slug]))
            ->assertOk();
    }

    // =====================================================================
    // 2. LA VUE : il voit le tenant, pas seulement ce dont il serait membre
    // =====================================================================

    public function test_il_voit_TOUTES_les_Boucles_de_l_Organization(): void
    {
        $sandbox = $this->sandboxChargee();

        $boucles = Loop::query()->where('organization_id', $sandbox->id)->where('status', 'active')->get();
        $this->assertGreaterThanOrEqual(2, $boucles->count(), 'La sandbox doit porter plusieurs Boucles.');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.index', ['organization' => $sandbox->slug]))
            ->assertOk()
            ->getContent();

        // Il n'est membre d'AUCUNE, et doit pourtant toutes les voir nommees.
        foreach ($boucles as $boucle) {
            $this->assertStringContainsString(
                e($boucle->name),
                $html,
                sprintf('La Boucle « %s » du tenant n est pas visible.', $boucle->name)
            );
        }
    }

    public function test_il_voit_un_evenement_d_une_Boucle_dont_il_n_est_PAS_membre(): void
    {
        $sandbox = $this->sandboxChargee();
        $boucle = Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();

        // Un evenement de BOUCLE, pas remonte au niveau Organization : c'est
        // exactement celui que le filtre d'appartenance cachait.
        $evenement = $this->evenementDeBoucle($sandbox, $boucle, LoopEvent::VISIBILITY_LOOP, 'Atelier interne de la Boucle');

        $this->assertSame(0, LoopMember::query()
            ->where('loop_id', $boucle->id)->where('user_id', $this->superAdmin->id)->count());

        $this->actingAs($this->superAdmin)
            ->get(route('organization.events.agenda', ['organization' => $sandbox->slug]))
            ->assertOk()
            ->assertSee($evenement->title);
    }

    // =====================================================================
    // 3. LE CLOISONNEMENT : rien d'un autre tenant
    // =====================================================================

    public function test_sous_l_URL_d_un_tenant_AUCUNE_donnee_d_un_autre_n_apparait(): void
    {
        $sandbox = $this->sandboxChargee();

        // Un tenant TIERS, avec sa Boucle et son evenement.
        $autre = Organization::factory()->create(['slug' => 'tenant-tiers']);
        $proprietaire = User::factory()->create(['organization_id' => $autre->id]);
        $boucleTierce = Loop::factory()->create([
            'organization_id' => $autre->id,
            'created_by' => $proprietaire->id,
            'name' => 'Boucle dun autre tenant',
        ]);
        LoopMember::create(['loop_id' => $boucleTierce->id, 'user_id' => $proprietaire->id, 'role' => 'owner']);
        $evenementTiers = $this->evenementDeBoucle($autre, $boucleTierce, LoopEvent::VISIBILITY_ORGANIZATION, 'Rencontre dun autre tenant');

        foreach (['organization.loops.index', 'organization.events.agenda'] as $route) {
            $html = $this->actingAs($this->superAdmin)
                ->get(route($route, ['organization' => $sandbox->slug]))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString(e($boucleTierce->name), $html, $route);
            $this->assertStringNotContainsString(e($evenementTiers->title), $html, $route);
        }
    }

    // =====================================================================
    // 4. CE QU'ON N'A PAS ACHETE : aucun membership
    // =====================================================================

    public function test_aucun_membership_n_est_cree_pour_le_SuperAdmin(): void
    {
        $sandbox = $this->sandboxChargee();

        $this->actingAs($this->superAdmin)->get(route('organization.loops.index', ['organization' => $sandbox->slug]))->assertOk();
        $this->actingAs($this->superAdmin)->get(route('organization.events.agenda', ['organization' => $sandbox->slug]))->assertOk();

        $this->superAdmin->refresh();

        $this->assertSame(
            $this->organisationDuSuperAdmin->id,
            $this->superAdmin->organization_id,
            'Le SuperAdmin a change d Organization.'
        );

        $this->assertSame(0, LoopMember::query()
            ->whereIn('loop_id', Loop::query()->where('organization_id', $sandbox->id)->pluck('id'))
            ->where('user_id', $this->superAdmin->id)
            ->count(), 'Un LoopMember a ete cree pour le SuperAdmin.');
    }

    // =====================================================================
    // 5. LES AUTRES RESTENT TRAITES COMME AVANT
    // =====================================================================

    public function test_un_utilisateur_externe_NON_SuperAdmin_reste_refuse(): void
    {
        $sandbox = $this->sandboxChargee();

        $etranger = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => false,
        ]);

        foreach (['organization.loops.index', 'organization.events.agenda'] as $route) {
            $this->actingAs($etranger)
                ->get(route($route, ['organization' => $sandbox->slug]))
                ->assertNotFound();
        }
    }

    public function test_un_membre_ordinaire_du_tenant_garde_un_comportement_INCHANGE(): void
    {
        $sandbox = $this->sandboxChargee();
        $boucle = Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();

        // Membre de l'Organization, membre d'AUCUNE Boucle.
        $membre = User::factory()->create(['organization_id' => $sandbox->id, 'is_admin' => false]);

        $evenementDeBoucle = $this->evenementDeBoucle($sandbox, $boucle, LoopEvent::VISIBILITY_LOOP, 'Atelier reserve a la Boucle');

        $this->actingAs($membre)
            ->get(route('organization.events.agenda', ['organization' => $sandbox->slug]))
            ->assertOk()
            // Le filtre d'appartenance vaut toujours pour lui : c'est la regle
            // existante, et T1658 ne l'a pas elargie.
            ->assertDontSee($evenementDeBoucle->title);

        $this->actingAs($membre)
            ->get(route('organization.loops.index', ['organization' => $sandbox->slug]))
            ->assertOk();
    }

    // =====================================================================
    // 6. ACCES n'est pas PARTICIPATION
    // =====================================================================

    public function test_le_SuperAdmin_ne_peut_PAS_repondre_a_un_evenement_du_tenant(): void
    {
        $sandbox = $this->sandboxChargee();
        $boucle = Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();
        $evenement = $this->evenementDeBoucle($sandbox, $boucle, LoopEvent::VISIBILITY_ORGANIZATION, 'Atelier ouvert');

        // Inspecter oui, agir non. La garde de l'action reste celle d'avant, et
        // T1658 ne l'a pas touchee.
        $this->actingAs($this->superAdmin)
            ->post(route('organization.events.agenda.respond', [
                'organization' => $sandbox->slug,
                'event' => $evenement->id,
            ]), ['response' => 'going'])
            ->assertNotFound();

        $this->assertDatabaseMissing('loop_event_responses', [
            'event_id' => $evenement->id,
            'user_id' => $this->superAdmin->id,
        ]);
    }

    // =====================================================================
    // 7. L'autorite elle-meme
    // =====================================================================

    public function test_l_autorite_d_acces_dit_exactement_ce_qu_elle_promet(): void
    {
        $sandbox = $this->sandboxChargee();
        $membre = User::factory()->create(['organization_id' => $sandbox->id, 'is_admin' => false]);
        $etranger = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => false,
        ]);

        $this->assertTrue($this->superAdmin->canAccessOrganization($sandbox), 'SuperAdmin : partout.');
        $this->assertTrue($membre->canAccessOrganization($sandbox), 'Membre : chez lui.');
        $this->assertFalse($etranger->canAccessOrganization($sandbox), 'Etranger : nulle part.');

        // Un cas limite qui merite d'etre ferme : sans Organization resolue,
        // personne ne passe — pas meme le SuperAdmin, faute d'objet a autoriser.
        $this->assertFalse($membre->canAccessOrganization(null));
        $this->assertFalse($this->superAdmin->canAccessOrganization(null));
    }

    // =====================================================================
    // Outils
    // =====================================================================

    private function sandboxChargee(): Organization
    {
        $json = (string) file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));

        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        $cycle = app(ScenarioLifecycleService::class);
        $cycle->approve($version->fresh(), $this->superAdmin);
        $cycle->load($version->fresh());

        $load = ScenarioPackLoad::query()->findOrFail($version->fresh()->scenario_pack_load_id);

        return Organization::query()->findOrFail($load->organization_id);
    }

    private function evenementDeBoucle(Organization $organization, Loop $boucle, string $visibilite, string $titre): LoopEvent
    {
        $organisateur = User::query()->where('organization_id', $organization->id)->firstOrFail();

        return LoopEvent::create([
            'organization_id' => $organization->id,
            'loop_id' => $boucle->id,
            'created_by' => $organisateur->id,
            'title' => $titre,
            'format' => LoopEvent::FORMAT_ONLINE,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHour(),
            'timezone' => 'Europe/Paris',
            'meeting_url' => 'https://example.test/rencontre',
            'visibility' => $visibilite,
            'status' => LoopEvent::STATUS_SCHEDULED,
        ]);
    }
}
