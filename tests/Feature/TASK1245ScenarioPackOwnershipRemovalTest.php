<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopPollVote;
use App\Models\LoopRoadmapItem;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOwnershipUnknownException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackReusedEntityMutatedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackStoragePathCollisionException;
use App\Support\ScenarioPacks\Packs\ArtSciLabDemoPack;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Database\Seeders\ArtSciLabScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ScenarioPacks\OwnershipProbeScenarioPack;
use Tests\TestCase;

/**
 * TASK-1245 — retrait PHYSIQUE borne du ScenarioPackRemover, gouverne par
 * l'ownership du registre.
 *
 * Contrat : STATE BEFORE -> LOAD PACK -> USE -> DELETE PACK -> STATE BEFORE.
 *
 * Invariants prouves ici (arbitrage MASTER 1) :
 *  A. le registre trace la PARTICIPATION ; B. `ownership=created` donne le
 *  droit de destruction ; C. fixe a la premiere inscription ; D. un reset
 *  ne transforme jamais `created` en `reused` ; E. `reused` = reference
 *  seule (ni supprimee, ni modifiee) ; F. ownership inconnu -> refus
 *  explicite, aucune purge partielle ; G. SoftDeletes : forceDelete borne
 *  aux `created` ; H. Category trackee, `created`/`reused` selon la
 *  realite physique ; I. DossierFile `created` : ligne DB ET fichier ;
 *  J. fichier preexistant jamais ecrase ni supprime ; K. ScenarioPackLoad
 *  inchange.
 *
 * Sections A-C : pack sonde `OwnershipProbeScenarioPack` (chaque entite
 * pilotee par le test). Section D : pack reel ArtSciLab (les 20 types
 * trackes + Category + storage).
 */
class TASK1245ScenarioPackOwnershipRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private Organization $artsci;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OwnershipProbeScenarioPack::DISK);

        $this->organization = Organization::factory()->create(['slug' => 't1245-org-a']);
        $this->otherOrganization = Organization::factory()->create(['slug' => 't1245-org-b']);
        $this->artsci = Organization::factory()->create(['slug' => ArtSciLabScenarioSeeder::SLUG]);

        config([
            'scenario_packs.allowed_organizations' => [
                $this->organization->slug,
                $this->otherOrganization->slug,
                $this->artsci->slug,
            ],
        ]);
    }

    /** @return array<string, string|null> internal_key -> ownership, pour un pack dans une Organization */
    private function ownershipByKey(string $packId, Organization $organization): array
    {
        $load = ScenarioPackLoad::query()->where('organization_id', $organization->id)->where('pack_id', $packId)->firstOrFail();

        return ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->id)
            ->orderBy('sequence')
            ->get(['entity_type', 'internal_key', 'ownership'])
            ->mapWithKeys(fn (ScenarioPackEntity $e) => [$e->entity_type.'|'.$e->internal_key => $e->ownership])
            ->all();
    }

    private function loadProbe(Organization $organization, OwnershipProbeScenarioPack $pack = new OwnershipProbeScenarioPack): void
    {
        app(ScenarioPackLoader::class)->load($pack, $organization);
    }

    // =====================================================================
    // A. Ownership au chargement (invariants A, B, C, E)
    // =====================================================================

    public function test_first_load_registers_every_entity_the_pack_physically_creates_as_created(): void
    {
        $this->loadProbe($this->organization);

        $this->assertSame([
            'persona|persona-1' => ScenarioPackEntity::OWNERSHIP_CREATED,
            'folder|folder-1' => ScenarioPackEntity::OWNERSHIP_CREATED,
            'folder_file|file-1' => ScenarioPackEntity::OWNERSHIP_CREATED,
            'loop|shared-loop' => ScenarioPackEntity::OWNERSHIP_CREATED,
        ], $this->ownershipByKey('t1245-ownership-probe', $this->organization));
    }

    public function test_a_preexisting_entity_is_registered_as_reused_and_stays_untouched_through_load_reset_and_delete(): void
    {
        // STATE BEFORE : un persona et une Boucle existent deja, hors pack.
        $persona = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => OwnershipProbeScenarioPack::personaEmail($this->organization),
            'name' => 'Preexisting Person',
        ]);
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'slug' => OwnershipProbeScenarioPack::SHARED_LOOP_SLUG,
            'name' => 'Preexisting Loop Name',
            'created_by' => $persona->id,
        ]);
        $personaBefore = $persona->fresh()->getAttributes();
        $loopBefore = $loop->fresh()->getAttributes();

        $this->loadProbe($this->organization);

        $ownership = $this->ownershipByKey('t1245-ownership-probe', $this->organization);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $ownership['persona|persona-1']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $ownership['loop|shared-loop']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_CREATED, $ownership['folder|folder-1']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_CREATED, $ownership['folder_file|file-1']);
        $this->assertSame($personaBefore, $persona->fresh()->getAttributes(), 'load() a modifie un persona reutilise');
        $this->assertSame($loopBefore, $loop->fresh()->getAttributes(), 'load() a modifie une Boucle reutilisee');

        app(ScenarioPackResetter::class)->reset(new OwnershipProbeScenarioPack, $this->organization);

        $ownership = $this->ownershipByKey('t1245-ownership-probe', $this->organization);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $ownership['persona|persona-1']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $ownership['loop|shared-loop']);
        $this->assertSame($personaBefore, $persona->fresh()->getAttributes(), 'reset() a modifie un persona reutilise');
        $this->assertSame($loopBefore, $loop->fresh()->getAttributes(), 'reset() a modifie une Boucle reutilisee');

        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);

        // STATE BEFORE retrouve : les entites reutilisees sont la, intactes ;
        // celles que le pack avait creees ont disparu physiquement.
        $this->assertSame($personaBefore, $persona->fresh()->getAttributes(), 'remove() a modifie un persona reutilise');
        $this->assertSame($loopBefore, $loop->fresh()->getAttributes(), 'remove() a modifie une Boucle reutilisee');
        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, DossierFile::withTrashed()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
        $this->assertSame(0, ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, ScenarioPackEntity::query()->where('organization_id', $this->organization->id)->count());
    }

    public function test_a_pack_that_mutates_a_preexisting_entity_is_refused_and_nothing_is_kept(): void
    {
        $persona = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => OwnershipProbeScenarioPack::personaEmail($this->organization),
        ]);
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'slug' => OwnershipProbeScenarioPack::SHARED_LOOP_SLUG,
            'name' => 'Preexisting Loop Name',
            'created_by' => $persona->id,
        ]);
        $loopBefore = $loop->fresh()->getAttributes();

        try {
            $this->loadProbe($this->organization, new OwnershipProbeScenarioPack(mutateSharedLoop: true));
            $this->fail('Une entite reutilisee mutee par le pack aurait du faire refuser le chargement.');
        } catch (ScenarioPackReusedEntityMutatedException $e) {
            $this->assertStringContainsString('loop:shared-loop', $e->getMessage());
            $this->assertStringContainsString('name', $e->getMessage());
        }

        // Rien n'a ete conserve : ni la mutation, ni le chargement, ni les
        // entites que le pack avait creees avant l'echec (rollback DB) — ni
        // le fichier qu'il avait deja ecrit sur le disque (le storage ne suit
        // pas le rollback : nettoye explicitement par le loader).
        $this->assertSame($loopBefore, $loop->fresh()->getAttributes(), 'La mutation refusee a ete conservee.');
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, DossierFile::withTrashed()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    public function test_a_later_pack_version_that_mutates_a_reused_entity_is_refused_at_reset_too(): void
    {
        $persona = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => OwnershipProbeScenarioPack::personaEmail($this->organization),
        ]);
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'slug' => OwnershipProbeScenarioPack::SHARED_LOOP_SLUG,
            'name' => 'Preexisting Loop Name',
            'created_by' => $persona->id,
        ]);
        $loopBefore = $loop->fresh()->getAttributes();

        // Premier chargement : la Boucle est retrouvee telle quelle (reused).
        $this->loadProbe($this->organization);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $this->ownershipByKey('t1245-ownership-probe', $this->organization)['loop|shared-loop']);

        // Version 2 du pack : elle "renomme" la Boucle partagee au passage.
        // La garde ne vaut pas qu'a la premiere inscription.
        try {
            app(ScenarioPackResetter::class)->reset(new OwnershipProbeScenarioPack(version: '2.0.0', mutateSharedLoop: true), $this->organization);
            $this->fail('Une mutation d\'entite reutilisee au reset aurait du etre refusee.');
        } catch (ScenarioPackReusedEntityMutatedException $e) {
            $this->assertStringContainsString('loop:shared-loop', $e->getMessage());
        }

        $this->assertSame($loopBefore, $loop->fresh()->getAttributes(), 'La mutation refusee au reset a ete conservee.');
        $load = ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->sole();
        $this->assertSame('1.0.0', $load->pack_version);
        $this->assertNull($load->reset_at);
    }

    // =====================================================================
    // B. Ownership immuable au reset, purge physique (invariants C, D, G, I)
    // =====================================================================

    public function test_ownership_stays_created_across_reset_then_delete_purges_physically(): void
    {
        $this->loadProbe($this->organization);
        $before = $this->ownershipByKey('t1245-ownership-probe', $this->organization);
        $this->assertNotContains(ScenarioPackEntity::OWNERSHIP_REUSED, $before);
        $this->assertNotContains(null, $before);

        // Au reset, `wasRecentlyCreated` est false pour TOUT (les entites
        // existent depuis le premier chargement) : ce n'est jamais une raison
        // de degrader `created` en `reused`.
        app(ScenarioPackResetter::class)->reset(new OwnershipProbeScenarioPack, $this->organization);
        app(ScenarioPackResetter::class)->reset(new OwnershipProbeScenarioPack, $this->organization);
        $this->assertSame($before, $this->ownershipByKey('t1245-ownership-probe', $this->organization));

        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);

        $this->assertSame(0, User::query()->where('email', OwnershipProbeScenarioPack::personaEmail($this->organization))->count());
        $this->assertSame(0, Loop::query()->where('organization_id', $this->organization->id)->where('slug', OwnershipProbeScenarioPack::SHARED_LOOP_SLUG)->count());
        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, DossierFile::withTrashed()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    /**
     * Regression guard de la decouverte phase 1 : avant T1245, Dossier &
     * co. ne disparaissaient physiquement que par ACCIDENT de cascade DB
     * (`dossiers.owner_id` cascadeOnDelete depuis le hard-delete tardif du
     * persona). Ici le persona PREEXISTE (reused, jamais supprime) : aucune
     * cascade ne peut aider, seul un forceDelete intentionnel purge le
     * Dossier et le DossierFile.
     */
    public function test_soft_deletes_owned_by_the_pack_are_purged_even_when_no_parent_cascade_can_help(): void
    {
        $persona = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => OwnershipProbeScenarioPack::personaEmail($this->organization),
        ]);

        $this->loadProbe($this->organization);
        $this->assertSame(1, Dossier::query()->where('organization_id', $this->organization->id)->where('owner_id', $persona->id)->count());
        $this->assertSame(1, DossierFile::query()->where('organization_id', $this->organization->id)->where('uploaded_by', $persona->id)->count());

        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);

        $this->assertTrue(User::query()->whereKey($persona->id)->exists(), 'Le persona preexistant a ete supprime.');
        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->organization->id)->count(), 'Dossier soft-delete residuel');
        $this->assertSame(0, DossierFile::withTrashed()->where('organization_id', $this->organization->id)->count(), 'DossierFile soft-delete residuel');
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    public function test_an_owned_entity_soft_deleted_during_use_is_still_purged_physically(): void
    {
        $this->loadProbe($this->organization);
        Dossier::query()->where('organization_id', $this->organization->id)->where('name', OwnershipProbeScenarioPack::DOSSIER_NAME)->sole()->delete();
        $this->assertSame(1, Dossier::onlyTrashed()->where('organization_id', $this->organization->id)->count());

        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);

        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->organization->id)->count());
    }

    public function test_reset_purges_an_orphan_owned_file_from_db_and_storage_but_only_unregisters_an_orphan_reused_entity(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'slug' => OwnershipProbeScenarioPack::SHARED_LOOP_SLUG,
            'name' => 'Preexisting Loop Name',
        ]);
        $this->loadProbe($this->organization);
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertExists(OwnershipProbeScenarioPack::filePath($this->organization));

        // Version 2 : abandonne la Boucle (reused) et le fichier (created).
        $result = app(ScenarioPackResetter::class)->reset(
            new OwnershipProbeScenarioPack(version: '2.0.0', includeSharedLoop: false, includeFile: false),
            $this->organization,
        );

        $this->assertEqualsCanonicalizing(['loop|shared-loop', 'folder_file|file-1'], $result->removedOrphans);
        $this->assertTrue(Loop::query()->whereKey($loop->id)->exists(), 'Un orphelin reutilise a ete supprime.');
        $this->assertSame(0, DossierFile::withTrashed()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
        $this->assertSame(
            ['persona|persona-1', 'folder|folder-1'],
            array_keys($this->ownershipByKey('t1245-ownership-probe', $this->organization)),
        );
    }

    // =====================================================================
    // C. Refus explicites (invariants F, J), cross-tenant, reload
    // =====================================================================

    public function test_delete_is_refused_without_any_partial_deletion_when_an_ownership_is_unknown(): void
    {
        $this->loadProbe($this->organization);
        $load = ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->sole();
        // Simule une ligne inscrite avant la migration T1245.
        DB::table('scenario_pack_entities')->where('scenario_pack_load_id', $load->id)->where('entity_type', 'folder')->update(['ownership' => null]);

        try {
            app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);
            $this->fail('Un ownership inconnu aurait du faire refuser la suppression.');
        } catch (ScenarioPackOwnershipUnknownException $e) {
            $this->assertStringContainsString('folder=1', $e->getMessage());
            $this->assertStringContainsString('suppression refuse', $e->getMessage());
        }

        // Rien n'a ete supprime, meme pas les entites dont l'ownership etait
        // connu (pas de purge partielle suivie de la destruction du registre).
        $this->assertTrue(ScenarioPackLoad::query()->whereKey($load->id)->exists());
        $this->assertSame(4, ScenarioPackEntity::query()->where('scenario_pack_load_id', $load->id)->count());
        $this->assertSame(1, User::query()->where('email', OwnershipProbeScenarioPack::personaEmail($this->organization))->count());
        $this->assertSame(1, Loop::query()->where('organization_id', $this->organization->id)->where('slug', OwnershipProbeScenarioPack::SHARED_LOOP_SLUG)->count());
        $this->assertSame(1, Dossier::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, DossierFile::query()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertExists(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    public function test_reset_is_refused_when_an_orphan_has_unknown_ownership(): void
    {
        $this->loadProbe($this->organization);
        $load = ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->sole();
        DB::table('scenario_pack_entities')->where('scenario_pack_load_id', $load->id)->where('entity_type', 'folder_file')->update(['ownership' => null]);

        $this->expectException(ScenarioPackOwnershipUnknownException::class);
        app(ScenarioPackResetter::class)->reset(new OwnershipProbeScenarioPack(version: '2.0.0', includeFile: false), $this->organization);
    }

    public function test_reset_refused_for_unknown_ownership_leaves_the_orphan_file_in_db_and_storage(): void
    {
        $this->loadProbe($this->organization);
        $load = ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->sole();
        DB::table('scenario_pack_entities')->where('scenario_pack_load_id', $load->id)->where('entity_type', 'folder_file')->update(['ownership' => null]);

        try {
            app(ScenarioPackResetter::class)->reset(new OwnershipProbeScenarioPack(version: '2.0.0', includeFile: false), $this->organization);
        } catch (ScenarioPackOwnershipUnknownException) {
        }

        $this->assertSame(1, DossierFile::query()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertExists(OwnershipProbeScenarioPack::filePath($this->organization));
        $this->assertSame('1.0.0', $load->fresh()->pack_version);
    }

    public function test_a_preexisting_storage_file_at_the_packs_path_is_never_overwritten_and_refuses_the_load(): void
    {
        $path = OwnershipProbeScenarioPack::filePath($this->organization);
        Storage::disk(OwnershipProbeScenarioPack::DISK)->put($path, 'contenu preexistant, hors pack');

        try {
            $this->loadProbe($this->organization);
            $this->fail('Un fichier preexistant au chemin du pack aurait du faire refuser le chargement.');
        } catch (ScenarioPackStoragePathCollisionException $e) {
            $this->assertStringContainsString($path, $e->getMessage());
        }

        $this->assertSame('contenu preexistant, hors pack', Storage::disk(OwnershipProbeScenarioPack::DISK)->get($path));
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, DossierFile::withTrashed()->count());
    }

    public function test_a_failed_load_cleans_up_the_file_it_wrote_so_that_a_clean_retry_is_accepted(): void
    {
        // La Boucle preexistante est mutee par le pack APRES l'ecriture du
        // fichier (ordre d'apply() de la sonde) : le fichier est deja sur le
        // disque quand le chargement est refuse. Sans nettoyage, la garde de
        // collision refuserait ensuite tout nouvel essai, meme propre.
        Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'slug' => OwnershipProbeScenarioPack::SHARED_LOOP_SLUG,
            'name' => 'Preexisting Loop Name',
        ]);
        try {
            $this->loadProbe($this->organization, new OwnershipProbeScenarioPack(mutateSharedLoop: true));
            $this->fail('Le chargement mutant aurait du etre refuse.');
        } catch (ScenarioPackReusedEntityMutatedException) {
        }
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));

        // Nouvel essai sans mutation : accepte, la Boucle est simplement reutilisee.
        $this->loadProbe($this->organization);
        $this->assertSame(1, ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $this->ownershipByKey('t1245-ownership-probe', $this->organization)['loop|shared-loop']);
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertExists(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    public function test_delete_leaves_another_organizations_pack_completely_intact(): void
    {
        $this->loadProbe($this->organization);
        $this->loadProbe($this->otherOrganization);

        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);

        $this->assertSame(0, ScenarioPackLoad::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, ScenarioPackLoad::query()->where('organization_id', $this->otherOrganization->id)->count());
        $this->assertSame(4, ScenarioPackEntity::query()->where('organization_id', $this->otherOrganization->id)->count());
        $this->assertSame(1, User::query()->where('email', OwnershipProbeScenarioPack::personaEmail($this->otherOrganization))->count());
        $this->assertSame(1, Loop::query()->where('organization_id', $this->otherOrganization->id)->where('slug', OwnershipProbeScenarioPack::SHARED_LOOP_SLUG)->count());
        $this->assertSame(1, Dossier::query()->where('organization_id', $this->otherOrganization->id)->count());
        $this->assertSame(1, DossierFile::query()->where('organization_id', $this->otherOrganization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertExists(OwnershipProbeScenarioPack::filePath($this->otherOrganization));
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    public function test_reload_after_a_complete_delete_works_and_is_again_fully_owned(): void
    {
        $this->loadProbe($this->organization);
        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);
        $this->loadProbe($this->organization);

        $ownership = $this->ownershipByKey('t1245-ownership-probe', $this->organization);
        $this->assertCount(4, $ownership);
        $this->assertSame([ScenarioPackEntity::OWNERSHIP_CREATED], array_values(array_unique($ownership)));
        $this->assertSame(1, Dossier::query()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertExists(OwnershipProbeScenarioPack::filePath($this->organization));

        app(ScenarioPackRemover::class)->remove('t1245-ownership-probe', $this->organization);
        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->organization->id)->count());
        Storage::disk(OwnershipProbeScenarioPack::DISK)->assertMissing(OwnershipProbeScenarioPack::filePath($this->organization));
    }

    // =====================================================================
    // D. Pack reel ArtSciLab (invariants G, H, I sur les 20 types + Category)
    // =====================================================================

    /** @var list<class-string> les modeles trackes par ArtSciLabDemoPack qui portent organization_id */
    private const ARTSCI_MODELS = [
        User::class, Loop::class, LoopMember::class, LoopMessage::class,
        ServiceRequest::class, Service::class, Transaction::class, PointLedger::class,
        BlogPost::class, Dossier::class, DossierMember::class, DossierBlogPost::class,
        DossierFile::class, MemberAiProfile::class, LoopEvent::class, LoopEventResponse::class,
        LoopPoll::class, LoopPollVote::class, LoopDecision::class, LoopRoadmapItem::class,
        Category::class,
    ];

    private function artsciPhysicalRowCount(string $modelClass): int
    {
        // withoutGlobalScopes : compte les lignes PHYSIQUES, soft-supprimees
        // comprises, sans dependre du current_organization.
        return $modelClass::query()->withoutGlobalScopes()->where('organization_id', $this->artsci->id)->count();
    }

    public function test_artscilab_delete_leaves_no_physical_row_in_any_table_and_no_storage_file(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabDemoPack, $this->artsci);
        $this->assertSame(18, $this->artsciPhysicalRowCount(DossierFile::class));
        $this->assertSame(4, $this->artsciPhysicalRowCount(Category::class));
        $this->assertCount(18, Storage::disk('dossier_files')->allFiles('artscilab-demo'));

        app(ScenarioPackRemover::class)->remove('artscilab-demo-test', $this->artsci);

        foreach (self::ARTSCI_MODELS as $modelClass) {
            $this->assertSame(0, $this->artsciPhysicalRowCount($modelClass), "{$modelClass} : ligne physique residuelle apres remove().");
        }
        $this->assertSame(0, DB::table('loop_poll_options')->count());
        $this->assertSame(0, DB::table('loop_poll_vote_options')->count());
        $this->assertSame(0, DB::table('loop_roadmap_item_user')->count());
        $this->assertSame(0, DB::table('dossier_chunks')->where('organization_id', $this->artsci->id)->count());
        $this->assertSame([], Storage::disk('dossier_files')->allFiles('artscilab-demo'));
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertTrue(Organization::query()->whereKey($this->artsci->id)->exists());
    }

    public function test_artscilab_categories_are_tracked_created_and_purged_fk_safe(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabDemoPack, $this->artsci);

        $ownership = $this->ownershipByKey('artscilab-demo-test', $this->artsci);
        $categoryKeys = array_filter(array_keys($ownership), fn (string $key) => str_starts_with($key, 'category|'));
        $this->assertCount(4, $categoryKeys);
        foreach ($categoryKeys as $key) {
            $this->assertSame(ScenarioPackEntity::OWNERSHIP_CREATED, $ownership[$key], $key);
        }
        // Les Category (inscrites avant marketplace()) ont une sequence plus
        // basse que Service/ServiceRequest : supprimees apres eux (FK RESTRICT).
        $load = ScenarioPackLoad::query()->sole();
        $maxCategorySeq = ScenarioPackEntity::query()->where('scenario_pack_load_id', $load->id)->where('entity_type', 'category')->max('sequence');
        $minServiceSeq = ScenarioPackEntity::query()->where('scenario_pack_load_id', $load->id)->whereIn('entity_type', ['marketplace_service', 'marketplace_request'])->min('sequence');
        $this->assertLessThan($minServiceSeq, $maxCategorySeq);

        app(ScenarioPackRemover::class)->remove('artscilab-demo-test', $this->artsci);

        $this->assertSame(0, Category::query()->where('slug', 'like', 'artscilab-%')->count());
        $this->assertSame(0, $this->artsciPhysicalRowCount(Service::class));
        $this->assertSame(0, $this->artsciPhysicalRowCount(ServiceRequest::class));
    }

    public function test_artscilab_preexisting_category_is_reused_and_kept_while_created_ones_are_purged(): void
    {
        // Meme slug artscilab-*, memes attributs : "prevue conceptuellement
        // par le pack" != "creee physiquement par ce chargement".
        $preexisting = Category::query()->create([
            'slug' => 'artscilab-creative-technology',
            'organization_id' => $this->artsci->id,
            'name_b2c' => 'Creative Technology',
            'name_b2b' => 'Creative Technology',
            'color' => '#7c3aed',
        ]);
        $before = $preexisting->fresh()->getAttributes();

        app(ScenarioPackLoader::class)->load(new ArtSciLabDemoPack, $this->artsci);

        $ownership = $this->ownershipByKey('artscilab-demo-test', $this->artsci);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $ownership['category|creative-technology']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_CREATED, $ownership['category|editorial-facilitation']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_CREATED, $ownership['category|european-projects']);
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_CREATED, $ownership['category|production-events']);
        $this->assertSame($before, $preexisting->fresh()->getAttributes());

        app(ScenarioPackRemover::class)->remove('artscilab-demo-test', $this->artsci);

        $this->assertSame($before, $preexisting->fresh()->getAttributes());
        $this->assertSame(1, Category::query()->where('slug', 'like', 'artscilab-%')->count());
        $this->assertSame(0, $this->artsciPhysicalRowCount(Service::class));
    }

    public function test_artscilab_ownership_stays_created_across_t1243_resets_then_delete_purges_everything(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabDemoPack, $this->artsci);
        $before = $this->ownershipByKey('artscilab-demo-test', $this->artsci);
        $this->assertSame([ScenarioPackEntity::OWNERSHIP_CREATED], array_values(array_unique($before)));

        app(ScenarioPackResetter::class)->reset(new ArtSciLabDemoPack, $this->artsci);
        app(ScenarioPackResetter::class)->reset(new ArtSciLabDemoPack, $this->artsci);
        $this->assertSame($before, $this->ownershipByKey('artscilab-demo-test', $this->artsci));

        app(ScenarioPackRemover::class)->remove('artscilab-demo-test', $this->artsci);

        foreach (self::ARTSCI_MODELS as $modelClass) {
            $this->assertSame(0, $this->artsciPhysicalRowCount($modelClass), $modelClass);
        }
        $this->assertSame([], Storage::disk('dossier_files')->allFiles('artscilab-demo'));
    }

    public function test_artscilab_reload_after_delete_produces_the_same_registry(): void
    {
        $first = app(ScenarioPackLoader::class)->load(new ArtSciLabDemoPack, $this->artsci);
        $firstOwnership = $this->ownershipByKey('artscilab-demo-test', $this->artsci);
        app(ScenarioPackRemover::class)->remove('artscilab-demo-test', $this->artsci);

        $second = app(ScenarioPackLoader::class)->load(new ArtSciLabDemoPack, $this->artsci);

        $this->assertTrue($second->wasFirstLoad);
        $this->assertSame($first->entityCountsByType, $second->entityCountsByType);
        $this->assertSame($firstOwnership, $this->ownershipByKey('artscilab-demo-test', $this->artsci));
        $this->assertCount(18, Storage::disk('dossier_files')->allFiles('artscilab-demo'));
    }
}
