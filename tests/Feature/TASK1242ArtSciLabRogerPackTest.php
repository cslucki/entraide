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
use App\Support\ScenarioPacks\Packs\ArtSciLabRogerPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Database\Seeders\ArtSciLabScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1242 — ArtSciLabRogerPack, premier pack reel du moteur T1240.
 *
 * Le contenu ligne-a-ligne d'ArtSciLabScenarioSeeder::compose() (11 personas,
 * 5 Loops, marketplace, blog, evenements, sondages, decisions...) est deja
 * exhaustivement verifie par TASK1203ArtSciLabScenarioSeederTest. Cette suite
 * ne reteste pas ce contenu ; elle verifie le CONTRAT scenario pack
 * specifique a T1242 :
 *  A. le pack se charge via le moteur T1240 (catalogue -> loader) dans
 *     l'Organization allowlistee 'artscilab-demo' ;
 *  B. le rechargement est idempotent (aucune duplication) ;
 *  C. le pack refuse d'ecrire dans toute autre Organization, meme
 *     allowlistee par ailleurs (S3 : jamais devinee depuis le contexte) ;
 *  D. la suppression bornee retire TOUT ce que le pack a cree, sans laisser
 *     une seule ligne PHYSIQUE orpheline (soft-supprimees comprises, TASK-1245)
 *     ni un fichier storage, dans TOUTES les tables touchees par le seeder,
 *     y compris les tables sans organization_id propre (loop_poll_options,
 *     loop_poll_vote_options, loop_roadmap_item_user) nettoyees par cascade
 *     depuis un parent reellement supprime ;
 *  E. le reset rejoue sans erreur.
 */
class TASK1242ArtSciLabRogerPackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        $this->organization = Organization::factory()->create(['slug' => ArtSciLabScenarioSeeder::SLUG]);

        config(['scenario_packs.allowed_organizations' => [ArtSciLabScenarioSeeder::SLUG]]);
        config(['scenario_packs.definitions' => ['artscilab-roger-demo' => ArtSciLabRogerPack::class]]);
    }

    // =====================================================================
    // A. Catalogue + chargement
    // =====================================================================

    public function test_the_catalog_resolves_the_registered_pack_id(): void
    {
        $pack = app(ScenarioPackCatalog::class)->get('artscilab-roger-demo');

        $this->assertInstanceOf(ArtSciLabRogerPack::class, $pack);
        $this->assertSame('artscilab-roger-demo', $pack->packId());
    }

    public function test_loading_creates_the_full_scenario_scoped_to_artscilab_demo(): void
    {
        $result = app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $this->organization);

        $this->assertTrue($result->wasFirstLoad);
        $this->assertSame(
            ScenarioPackEntity::query()->where('scenario_pack_load_id', $result->load->id)->count(),
            $result->totalEntities(),
        );
        $this->assertGreaterThan(0, $result->totalEntities());

        // Echantillon représentatif, aligné sur les volumes déjà prouvés par
        // TASK1203ArtSciLabScenarioSeederTest.
        $this->assertSame(11, $result->entityCountsByType['persona']);
        $this->assertSame(5, $result->entityCountsByType['loop']);
        $this->assertSame(120, $result->entityCountsByType['interaction']);
        $this->assertSame(18, $result->entityCountsByType['folder_file']);
        $this->assertSame(15, $result->entityCountsByType['roadmap_item']);

        $this->assertSame(1, User::query()->where('organization_id', $this->organization->id)->where('email', 'maya@artscilab-demo.test')->count());
        $this->assertTrue($result->load->organization_id === $this->organization->id);
    }

    // =====================================================================
    // B. Idempotence
    // =====================================================================

    public function test_replaying_the_load_does_not_duplicate_anything(): void
    {
        $loader = app(ScenarioPackLoader::class);

        $first = $loader->load(new ArtSciLabRogerPack, $this->organization);
        $second = $loader->load(new ArtSciLabRogerPack, $this->organization);

        $this->assertTrue($first->wasFirstLoad);
        $this->assertFalse($second->wasFirstLoad);
        $this->assertSame($first->entityCountsByType, $second->entityCountsByType);
        $this->assertSame(1, ScenarioPackLoad::query()->count());
        $this->assertSame(11, User::query()->where('organization_id', $this->organization->id)->count());
    }

    // =====================================================================
    // C. Jamais une autre Organization, meme allowlistee
    // =====================================================================

    public function test_apply_refuses_a_different_organization_even_if_allowlisted(): void
    {
        $otherOrganization = Organization::factory()->create(['slug' => 'not-artscilab-demo']);
        config(['scenario_packs.allowed_organizations' => [ArtSciLabScenarioSeeder::SLUG, $otherOrganization->slug]]);

        $this->expectException(LogicException::class);

        try {
            app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $otherOrganization);
        } finally {
            $this->assertSame(0, ScenarioPackLoad::query()->count());
            $this->assertSame(0, User::query()->where('organization_id', $otherOrganization->id)->count());
            $this->assertSame(0, User::query()->where('email', 'maya@artscilab-demo.test')->count());
        }
    }

    // =====================================================================
    // D. Suppression bornee — preuve empirique, table par table
    // =====================================================================

    public function test_removal_leaves_no_active_row_in_any_table_the_pack_touched(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $this->organization);
        $organizationId = $this->organization->id;

        app(ScenarioPackRemover::class)->remove('artscilab-roger-demo', $this->organization);

        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());

        // TASK-1245 : Category est desormais trackee (ownership `created`)
        // et purgee FK-safe (inscrite avant Service/ServiceRequest, donc
        // supprimee apres eux) ; le remover purge PHYSIQUEMENT
        // (`forceDelete` borne a ownership=created), y compris les modeles
        // SoftDeletes — DossierFile compris, ligne DB et fichier storage.
        // Le compte est donc fait sans global scopes (soft-supprimees
        // comprises) : "plus une seule ligne physique", pas seulement "plus
        // aucune ligne active". Preuves detaillees par type et par
        // ownership : TASK1245ScenarioPackOwnershipRemovalTest.
        $scopedModels = [
            User::class, Loop::class, LoopMember::class, LoopMessage::class,
            ServiceRequest::class, Service::class, Transaction::class, PointLedger::class,
            BlogPost::class, Dossier::class, DossierMember::class, DossierBlogPost::class,
            DossierFile::class, MemberAiProfile::class, LoopEvent::class, LoopEventResponse::class,
            LoopPoll::class, LoopPollVote::class, LoopDecision::class, LoopRoadmapItem::class,
            Category::class,
        ];
        foreach ($scopedModels as $modelClass) {
            $this->assertSame(
                0,
                $modelClass::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->count(),
                "{$modelClass} left a physical row for artscilab-demo after removal."
            );
        }
        $this->assertSame([], Storage::disk('dossier_files')->allFiles('artscilab-demo'));

        // Tables filles sans organization_id propre (nettoyees par cascade
        // FK depuis loop_polls/loop_poll_votes/loop_roadmap_items, deja
        // vides ci-dessus) : le test est isole (RefreshDatabase, une seule
        // Organization chargee), donc un compte global suffit.
        $this->assertSame(0, DB::table('loop_poll_options')->count());
        $this->assertSame(0, DB::table('loop_poll_vote_options')->count());
        $this->assertSame(0, DB::table('loop_roadmap_item_user')->count());

        // L'Organization elle-meme n'est jamais touchee par la suppression
        // du pack (S12 : uniquement ce que le pack a cree).
        $this->assertTrue(Organization::query()->whereKey($organizationId)->exists());
    }

    public function test_removal_never_touches_a_pre_existing_entity_of_the_same_organization(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $this->organization);
        $outsider = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'preexisting@artscilab-demo.test',
        ]);

        app(ScenarioPackRemover::class)->remove('artscilab-roger-demo', $this->organization);

        $this->assertTrue(User::query()->whereKey($outsider->id)->exists());
    }

    // =====================================================================
    // E. Reset
    // =====================================================================

    public function test_reset_reapplies_the_pack_without_error(): void
    {
        app(ScenarioPackLoader::class)->load(new ArtSciLabRogerPack, $this->organization);

        $result = app(ScenarioPackResetter::class)->reset(new ArtSciLabRogerPack, $this->organization);

        $result->load->refresh();
        $this->assertNotNull($result->load->reset_at);
        $this->assertSame([], $result->removedOrphans);
        $this->assertSame(11, User::query()->where('organization_id', $this->organization->id)->count());
    }
}
