<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackCrossTenantException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackNotLoadedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAllowedException;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioPacks\FixtureScenarioPack;
use Tests\TestCase;

/**
 * TASK-1240 — moteur de chargement de scenario pack, Organization-scoped.
 *
 * Preuves attendues par le contrat TASK-1239 :
 *  A. CHARGEMENT — entites attendues creees, registre correct.
 *  B. IDEMPOTENCE (S10) — rejouer ne duplique rien.
 *  C. GARDE-FOU ALLOWLIST (S3) — Organization non qualifiee : refus, rien
 *     d'ecrit.
 *  D. CROSS-TENANT (S14) — deux Organizations qualifiees restent
 *     completement separees ; le registrar refuse une entite mal rattachee.
 *  E. STATUT (DoD roadmap) — l'etat du chargement est lisible sans
 *     reconstruction approximative.
 *  F. RESET (S11) — reapplication + retrait des orphelins d'une version
 *     anterieure, jamais hors registre.
 *  G. SUPPRESSION BORNEE (S12) — retire uniquement ce que CE pack a cree,
 *     aucun impact sur le reste de l'Organization ; no-op si rien de charge.
 *  H. COMMANDES ARTISAN — surface CLI de la tache.
 */
class TASK1240ScenarioPackEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Organization $foreignOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'task1240-fixture-org-a']);
        $this->otherOrganization = Organization::factory()->create(['slug' => 'task1240-fixture-org-b']);
        $this->foreignOrganization = Organization::factory()->create(['slug' => 'task1240-not-allowed']);

        config([
            'scenario_packs.allowed_organizations' => [
                $this->organization->slug,
                $this->otherOrganization->slug,
            ],
        ]);
    }

    // =====================================================================
    // A. Chargement
    // =====================================================================

    public function test_loading_creates_the_expected_entities_scoped_to_the_target_organization(): void
    {
        $pack = new FixtureScenarioPack;
        $result = app(ScenarioPackLoader::class)->load($pack, $this->organization);

        $this->assertTrue($result->wasFirstLoad);
        $this->assertSame(4, $result->totalEntities());
        $this->assertEquals(['persona' => 1, 'loop' => 1, 'folder' => 1, 'interaction' => 1], $result->entityCountsByType);

        $this->assertSame(1, User::query()->where('organization_id', $this->organization->id)->where('email', 'fixture-persona@task1240-demo.test')->count());
        $this->assertSame(1, Loop::query()->where('organization_id', $this->organization->id)->where('slug', 'task1240-fixture-loop')->count());
        $this->assertSame(1, Dossier::query()->where('organization_id', $this->organization->id)->where('name', 'Fixture Dossier')->count());
        $this->assertSame(1, LoopMessage::query()->where('organization_id', $this->organization->id)->count());

        $entities = ScenarioPackEntity::query()->where('scenario_pack_load_id', $result->load->id)->get();
        $this->assertCount(4, $entities);
        $this->assertTrue($entities->every(fn (ScenarioPackEntity $e) => $e->organization_id === $this->organization->id));
        $this->assertSame([1, 2, 3, 4], $entities->pluck('sequence')->sort()->values()->all());
    }

    // =====================================================================
    // B. Idempotence
    // =====================================================================

    public function test_replaying_the_same_load_creates_no_duplicates_and_reuses_the_same_entities(): void
    {
        $loader = app(ScenarioPackLoader::class);
        $pack = new FixtureScenarioPack;

        $first = $loader->load($pack, $this->organization);
        $second = $loader->load($pack, $this->organization);

        $this->assertTrue($first->wasFirstLoad);
        $this->assertFalse($second->wasFirstLoad);
        $this->assertTrue($first->load->is($second->load));
        $this->assertSame($first->entityCountsByType, $second->entityCountsByType);

        $this->assertSame(1, ScenarioPackLoad::query()->count());
        $this->assertSame(4, ScenarioPackEntity::query()->count());
        $this->assertSame(1, User::query()->where('email', 'fixture-persona@task1240-demo.test')->count());
        $this->assertSame(1, Loop::query()->where('slug', 'task1240-fixture-loop')->count());

        $firstEntityIds = ScenarioPackEntity::query()->where('scenario_pack_load_id', $first->load->id)->pluck('entity_id', 'internal_key');
        $secondEntityIds = ScenarioPackEntity::query()->where('scenario_pack_load_id', $second->load->id)->pluck('entity_id', 'internal_key');
        $this->assertSame($firstEntityIds->all(), $secondEntityIds->all());
    }

    // =====================================================================
    // C. Garde-fou allowlist
    // =====================================================================

    public function test_an_organization_outside_the_allowlist_is_refused_before_any_write(): void
    {
        $this->expectException(ScenarioPackOrganizationNotAllowedException::class);

        try {
            app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->foreignOrganization);
        } finally {
            $this->assertSame(0, ScenarioPackLoad::query()->count());
            $this->assertSame(0, ScenarioPackEntity::query()->count());
            $this->assertSame(0, User::query()->where('organization_id', $this->foreignOrganization->id)->count());
        }
    }

    // =====================================================================
    // D. Cross-tenant
    // =====================================================================

    public function test_two_allowed_organizations_get_fully_separate_entities(): void
    {
        $loader = app(ScenarioPackLoader::class);
        $resultA = $loader->load(new FixtureScenarioPack, $this->organization);
        $resultB = $loader->load(new FixtureScenarioPack, $this->otherOrganization);

        $this->assertNotSame($resultA->load->id, $resultB->load->id);
        $this->assertSame(2, ScenarioPackLoad::query()->where('pack_id', 'task1240-fixture')->count());
        $this->assertSame(8, ScenarioPackEntity::query()->count());

        $loopA = Loop::query()->where('organization_id', $this->organization->id)->where('slug', 'task1240-fixture-loop')->sole();
        $loopB = Loop::query()->where('organization_id', $this->otherOrganization->id)->where('slug', 'task1240-fixture-loop')->sole();
        $this->assertNotSame($loopA->id, $loopB->id);

        $this->assertSame(
            $this->organization->id,
            ScenarioPackEntity::query()->where('scenario_pack_load_id', $resultA->load->id)->pluck('organization_id')->unique()->sole()
        );
        $this->assertSame(
            $this->otherOrganization->id,
            ScenarioPackEntity::query()->where('scenario_pack_load_id', $resultB->load->id)->pluck('organization_id')->unique()->sole()
        );
    }

    public function test_the_registrar_refuses_an_entity_that_belongs_to_a_different_organization(): void
    {
        $load = ScenarioPackLoad::query()->create([
            'organization_id' => $this->organization->id,
            'pack_id' => 'task1240-mismatch',
            'pack_version' => '1.0.0',
            'loaded_at' => now(),
        ]);
        $registrar = new ScenarioPackEntityRegistrar($load);

        $foreignUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->expectException(ScenarioPackCrossTenantException::class);
        $registrar->track('persona', 'persona-1', $foreignUser);

        $this->assertSame(0, ScenarioPackEntity::query()->count());
    }

    // =====================================================================
    // E. Statut
    // =====================================================================

    public function test_status_is_readable_without_approximate_reconstruction(): void
    {
        $this->assertNull(ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->where('pack_id', 'task1240-fixture')->first());

        $result = app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->organization);

        $load = ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->where('pack_id', 'task1240-fixture')->firstOrFail();
        $this->assertSame('1.0.0', $load->pack_version);
        $this->assertNotNull($load->loaded_at);
        $this->assertNull($load->reset_at);
        $this->assertTrue($load->is($result->load));
    }

    // =====================================================================
    // F. Reset
    // =====================================================================

    public function test_reset_without_a_prior_load_throws(): void
    {
        $this->expectException(ScenarioPackNotLoadedException::class);
        app(ScenarioPackResetter::class)->reset(new FixtureScenarioPack, $this->organization);
    }

    public function test_reset_reapplies_the_pack_and_removes_orphans_from_a_previous_version(): void
    {
        app(ScenarioPackLoader::class)->load(new FixtureScenarioPack(version: '1.0.0', includeInteraction: true), $this->organization);
        $this->assertSame(1, LoopMessage::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(4, ScenarioPackEntity::query()->count());

        // Un "outsider" pre-existant de l'Organization, non lie au pack : ne
        // doit jamais etre touche.
        $outsider = Loop::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Boucle preexistante']);

        $result = app(ScenarioPackResetter::class)->reset(new FixtureScenarioPack(version: '2.0.0', includeInteraction: false), $this->organization);

        $this->assertEquals(['persona' => 1, 'loop' => 1, 'folder' => 1], $result->entityCountsByType);
        $this->assertSame(['interaction|interaction-1'], $result->removedOrphans);
        $this->assertSame(0, LoopMessage::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(3, ScenarioPackEntity::query()->count());

        $result->load->refresh();
        $this->assertSame('2.0.0', $result->load->pack_version);
        $this->assertNotNull($result->load->reset_at);

        // L'outsider survit intact.
        $this->assertTrue(Loop::query()->whereKey($outsider->id)->exists());
        // Les autres entites du pack (non orphelines) restent.
        $this->assertSame(1, User::query()->where('email', 'fixture-persona@task1240-demo.test')->count());
        $this->assertSame(1, Loop::query()->where('slug', 'task1240-fixture-loop')->count());
    }

    public function test_reset_never_touches_a_different_organizations_data(): void
    {
        app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->organization);
        app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->otherOrganization);

        app(ScenarioPackResetter::class)->reset(new FixtureScenarioPack(version: '2.0.0', includeInteraction: false), $this->organization);

        // L'autre Organization garde ses 4 entites (dont l'interaction),
        // intactes.
        $this->assertSame(1, LoopMessage::query()->where('organization_id', $this->otherOrganization->id)->count());
        $loadB = ScenarioPackLoad::query()->where('organization_id', $this->otherOrganization->id)->where('pack_id', 'task1240-fixture')->firstOrFail();
        $this->assertSame('1.0.0', $loadB->pack_version);
        $this->assertNull($loadB->reset_at);
    }

    // =====================================================================
    // G. Suppression bornee
    // =====================================================================

    public function test_delete_removes_only_this_packs_entities_and_leaves_the_rest_of_the_organization_untouched(): void
    {
        app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->organization);
        $outsider = Loop::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Boucle preexistante']);
        $outsiderUser = User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'preexisting@task1240-demo.test']);

        app(ScenarioPackRemover::class)->remove('task1240-fixture', $this->organization);

        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertSame(0, User::query()->where('email', 'fixture-persona@task1240-demo.test')->count());
        $this->assertSame(0, Loop::query()->where('slug', 'task1240-fixture-loop')->count());
        $this->assertSame(0, LoopMessage::query()->where('organization_id', $this->organization->id)->count());

        $this->assertTrue(Loop::query()->whereKey($outsider->id)->exists());
        $this->assertTrue(User::query()->whereKey($outsiderUser->id)->exists());
    }

    public function test_delete_never_touches_a_different_organizations_pack_load(): void
    {
        app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->organization);
        app(ScenarioPackLoader::class)->load(new FixtureScenarioPack, $this->otherOrganization);

        app(ScenarioPackRemover::class)->remove('task1240-fixture', $this->organization);

        $this->assertSame(0, ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, ScenarioPackLoad::query()->where('organization_id', $this->otherOrganization->id)->count());
        $this->assertSame(1, LoopMessage::query()->where('organization_id', $this->otherOrganization->id)->count());
    }

    public function test_delete_without_a_prior_load_is_a_safe_noop(): void
    {
        app(ScenarioPackRemover::class)->remove('task1240-never-loaded', $this->organization);

        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
    }

    // =====================================================================
    // H. Commandes Artisan
    // =====================================================================

    public function test_the_artisan_commands_load_show_status_reset_and_delete(): void
    {
        config(['scenario_packs.definitions' => [
            'task1240-fixture' => FixtureScenarioPack::class,
        ]]);

        $this->artisan('scenario-pack:load', ['pack' => 'task1240-fixture', 'organization' => $this->organization->slug])
            ->assertSuccessful();
        $this->assertSame(4, ScenarioPackEntity::query()->count());

        $this->artisan('scenario-pack:status', ['pack' => 'task1240-fixture', 'organization' => $this->organization->slug])
            ->assertSuccessful();

        $this->artisan('scenario-pack:reset', ['pack' => 'task1240-fixture', 'organization' => $this->organization->slug, '--yes' => true])
            ->assertSuccessful();

        $this->artisan('scenario-pack:delete', ['pack' => 'task1240-fixture', 'organization' => $this->organization->slug, '--yes' => true])
            ->assertSuccessful();
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
    }

    public function test_the_load_command_refuses_an_unknown_organization_slug(): void
    {
        config(['scenario_packs.definitions' => [
            'task1240-fixture' => FixtureScenarioPack::class,
        ]]);

        $this->artisan('scenario-pack:load', ['pack' => 'task1240-fixture', 'organization' => 'does-not-exist'])
            ->assertFailed();
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }
}
