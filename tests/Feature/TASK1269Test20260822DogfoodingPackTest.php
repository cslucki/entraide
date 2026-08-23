<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierFileChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Services\Dossiers\FileContentExtractor;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1269 — Test20260822DogfoodingPack : le contrat du pack de dogfooding
 * de Cyril sur une fixture qui reproduit la forme du vrai corpus
 * (`_temp/Test_Rag-2026-08-22`, hors git) : les 10 repertoires DECLARES
 * (T1274 : `LOOP_DIRECTORIES`, 8 vides + 2 garnis de 6 fichiers), noms de
 * Loops avec accents/espaces/apostrophes, fichiers texte et binaires, un nom
 * trompeur (`.md` au contenu PNG) pour prouver que le MIME vient du contenu.
 */
class TASK1269Test20260822DogfoodingPackTest extends TestCase
{
    use RefreshDatabase;

    /** Deux des 10 repertoires declares (`LOOP_DIRECTORIES[6]`, `[7]`), garnis. */
    private const LOOP_A = '07-Plan-262 Définition boucles et IA';

    private const LOOP_B = "08-Protocole d'emergence";

    private const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    private Organization $organization;

    private string $source;

    /** @var array<string, User> */
    private array $personas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake(Test20260822DogfoodingPack::DISK);

        $this->organization = Organization::factory()->create([
            'slug' => Test20260822DogfoodingPack::ORGANIZATION_SLUG,
            'name' => 'test20260822',
            'loops_enabled' => true,
        ]);

        foreach (Test20260822DogfoodingPack::PERSONA_EMAILS as $key => $email) {
            $this->personas[$key] = User::factory()->create([
                'email' => $email,
                'organization_id' => $this->organization->id,
            ]);
        }
        $this->organization->update(['admin_id' => $this->personas['test_cyril']->id]);

        $this->source = sys_get_temp_dir().'/task1269-'.uniqid();
        // T1274 : le pack exige les 10 repertoires declares ; 8 restent vides.
        foreach (Test20260822DogfoodingPack::LOOP_DIRECTORIES as $name) {
            File::makeDirectory($this->source.'/'.$name, 0755, true);
        }
        $this->assertContains(self::LOOP_A, Test20260822DogfoodingPack::LOOP_DIRECTORIES);
        $this->assertContains(self::LOOP_B, Test20260822DogfoodingPack::LOOP_DIRECTORIES);
        File::put($this->source.'/'.self::LOOP_A.'/01-boucle, le format d’interaction universel.md', "# Boucle\n\nLe format d'interaction universel.\n");
        File::put($this->source.'/'.self::LOOP_A.'/02-Notes.txt', "Notes en texte brut.\n");
        File::put($this->source.'/'.self::LOOP_A.'/03-Mockup.png', self::PNG_BYTES);
        File::put($this->source.'/'.self::LOOP_B.'/01-Protocole.md', "# Protocole\n\nEmergence.\n");
        // Nom trompeur : extension .md, contenu PNG -> le MIME doit venir du contenu.
        File::put($this->source.'/'.self::LOOP_B.'/02-faux-markdown.md', self::PNG_BYTES);
        // Markdown qui commence par du HTML : finfo dit text/html -> text/markdown.
        File::put($this->source.'/'.self::LOOP_B.'/03-code-html.md', "<div class=\"x\">\n<p>Texte</p>\n<script>let a = 1;</script>\n\n# Titre Markdown\n");
        $this->assertSame('text/html', mime_content_type($this->source.'/'.self::LOOP_B.'/03-code-html.md'), 'Prerequis de la fixture : finfo voit du HTML.');
        // Un fichier cache et un sous-sous-dossier sont ignores.
        File::put($this->source.'/'.self::LOOP_B.'/.DS_Store', 'x');
        File::makeDirectory($this->source.'/'.self::LOOP_B.'/ignore-moi', 0755, true);
        File::put($this->source.'/.hidden-dir-marker', 'x');

        config([
            'scenario_packs.allowed_organizations' => [Test20260822DogfoodingPack::ORGANIZATION_SLUG, 'artscilab-demo'],
            'scenario_packs.definitions' => [Test20260822DogfoodingPack::PACK_ID => Test20260822DogfoodingPack::class],
            Test20260822DogfoodingPack::SOURCE_CONFIG_KEY => $this->source,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    private function load(): void
    {
        $pack = app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID);
        app(ScenarioPackLoader::class)->load($pack, $this->organization);
    }

    // =====================================================================
    // A. Catalogue et configuration reelle
    // =====================================================================

    public function test_the_real_config_registers_the_pack_and_allowlists_test20260822_only_in_addition_to_artscilab(): void
    {
        $config = require base_path('config/scenario_packs.php');

        $this->assertSame(
            ['artscilab-demo', 'test20260822'],
            $config['allowed_organizations'],
            'Seules artscilab-demo et test20260822 sont allowlistees — jamais main.'
        );
        $this->assertSame(Test20260822DogfoodingPack::class, $config['definitions']['test20260822-dogfooding']);
        $this->assertArrayHasKey('test20260822-dogfooding', $config['sources']);
    }

    public function test_the_catalog_resolves_the_pack(): void
    {
        $pack = app(ScenarioPackCatalog::class)->get('test20260822-dogfooding');

        $this->assertInstanceOf(Test20260822DogfoodingPack::class, $pack);
        $this->assertSame('test20260822', Test20260822DogfoodingPack::ORGANIZATION_SLUG);
    }

    // =====================================================================
    // B. Chargement : Loops canoniques, Dossier racine, fichiers
    // =====================================================================

    public function test_loading_creates_one_loop_per_source_directory_named_exactly_like_it_through_the_canonical_chain(): void
    {
        $this->load();

        $loops = Loop::query()->where('organization_id', $this->organization->id)->orderBy('name')->get();

        $this->assertSame(Test20260822DogfoodingPack::LOOP_DIRECTORIES, $loops->pluck('name')->all(), 'Une Loop par repertoire declare, nommee comme lui, accents et apostrophes compris.');

        foreach ($loops as $loop) {
            $this->assertSame('active', $loop->status);
            $this->assertSame('public', $loop->visibility);
            $this->assertSame($this->personas['test_cyril']->id, $loop->created_by);

            // Chaine canonique LoopService : membre owner + preset de cartes.
            $this->assertDatabaseHas('loop_members', ['loop_id' => $loop->id, 'user_id' => $this->personas['test_cyril']->id, 'role' => 'owner', 'status' => 'active']);
            $this->assertGreaterThan(0, LoopCard::query()->where('loop_id', $loop->id)->count(), 'applyPreset() a ete applique.');

            // LoopRootDocumentService : UN Dossier racine tenu par la Loop,
            // nomme comme elle, sans proprietaire, et un document racine.
            $roots = Dossier::query()->where('loop_id', $loop->id)->get();
            $this->assertCount(1, $roots);
            $root = $roots->first();
            $this->assertSame($loop->name, $root->name);
            $this->assertNull($root->owner_id);
            $this->assertNull($root->parent_id);
            $this->assertNotNull($root->root_blog_post_id);
            $this->assertTrue(BlogPost::query()->whereKey($root->root_blog_post_id)->where('organization_id', $this->organization->id)->exists());
            $this->assertSame($root->root_blog_post_id, $loop->fresh()->manifesto_blog_post_id);

            // Decision Cyril : aucun sous-dossier « Documents », aucun enfant.
            $this->assertSame(0, Dossier::query()->where('parent_id', $root->id)->count());
            $this->assertSame(0, Dossier::query()->where('organization_id', $this->organization->id)->where('name', 'Documents')->count());
        }
    }

    public function test_files_land_in_the_root_dossier_with_original_name_content_based_mime_size_checksum_and_disk(): void
    {
        $this->load();

        $rootA = Dossier::query()->whereHas('loop', fn ($q) => $q->where('name', self::LOOP_A))->firstOrFail();
        $rootB = Dossier::query()->whereHas('loop', fn ($q) => $q->where('name', self::LOOP_B))->firstOrFail();

        $this->assertSame(6, DossierFile::query()->where('organization_id', $this->organization->id)->count(), '6 fichiers, le .DS_Store et le sous-sous-dossier ignores.');
        $this->assertSame(3, DossierFile::query()->where('dossier_id', $rootA->id)->count());
        $this->assertSame(3, DossierFile::query()->where('dossier_id', $rootB->id)->count());

        $markdown = DossierFile::query()->where('original_name', '01-boucle, le format d’interaction universel.md')->firstOrFail();
        $this->assertSame('01-boucle, le format d’interaction universel.md', $markdown->display_name);
        $this->assertSame('text/plain', $markdown->mime_type, 'Meme mecanisme que l\'upload UI : un .md ressort text/plain (contenu).');
        $this->assertSame(Test20260822DogfoodingPack::DISK, $markdown->disk);
        $this->assertSame($rootA->id, $markdown->dossier_id);
        $this->assertSame($this->organization->id, $markdown->organization_id);
        $this->assertSame($this->personas['test_cyril']->id, $markdown->uploaded_by);
        $this->assertSame('upload', $markdown->source);
        $expected = file_get_contents($this->source.'/'.self::LOOP_A.'/01-boucle, le format d’interaction universel.md');
        $this->assertSame(strlen($expected), $markdown->size_bytes);
        $this->assertSame(hash('sha256', $expected), $markdown->checksum_sha256);
        $this->assertStringStartsWith('dossier-files/'.$rootA->id.'/', $markdown->path, 'Meme arborescence que l\'upload UI.');
        Storage::disk(Test20260822DogfoodingPack::DISK)->assertExists($markdown->path);
        $this->assertSame($expected, Storage::disk(Test20260822DogfoodingPack::DISK)->get($markdown->path));

        $this->assertSame('text/plain', DossierFile::query()->where('original_name', '02-Notes.txt')->value('mime_type'));
        $this->assertSame('image/png', DossierFile::query()->where('original_name', '03-Mockup.png')->value('mime_type'));
        $this->assertSame('image/png', DossierFile::query()->where('original_name', '02-faux-markdown.md')->value('mime_type'), 'Le MIME vient du contenu, pas de l\'extension.');
        $this->assertSame('text/markdown', DossierFile::query()->where('original_name', '03-code-html.md')->value('mime_type'), 'Contenu texte devine text/html + extension md -> text/markdown (ce que FileContentExtractor appliquera).');
    }

    public function test_markdown_files_imported_by_the_pack_are_eligible_for_dossiers_index_files(): void
    {
        $this->load();
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'test20260822', '--dry-run' => true])
            ->expectsOutputToContain('4')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(
            4,
            DossierFile::query()->where('organization_id', $this->organization->id)->whereIn('mime_type', FileContentExtractor::SUPPORTED_MIME_TYPES)->count(),
            'Les trois vrais .md et le .txt sont indexables ; le faux .md (PNG) et le PNG ne le sont pas.'
        );
    }

    // =====================================================================
    // C. HARD GATE : aucun job sur la queue `default`
    // =====================================================================

    public function test_loading_pushes_no_job_at_all_while_an_ordinary_file_creation_would(): void
    {
        Queue::fake();

        $this->load();

        Queue::assertNothingPushed();
        Queue::assertNotPushed(IndexDossierFileChunks::class);

        // Temoin : le meme modele, cree sans la closure withoutEvents du pack,
        // declenche bien l'observer -> l'instrument de mesure fonctionne.
        $root = Dossier::query()->whereNotNull('loop_id')->where('organization_id', $this->organization->id)->firstOrFail();
        DossierFile::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $root->id,
            'uploaded_by' => $this->personas['test_cyril']->id,
            'disk' => Test20260822DogfoodingPack::DISK,
            'path' => 'dossier-files/'.$root->id.'/temoin.txt',
            'original_name' => 'temoin.txt',
            'display_name' => 'temoin.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 6,
            'checksum_sha256' => hash('sha256', 'temoin'),
            'source' => 'upload',
        ]);

        Queue::assertPushed(IndexDossierFileChunks::class, 1);
    }

    // =====================================================================
    // D. Idempotence, garde-fous
    // =====================================================================

    public function test_replaying_the_load_duplicates_nothing_and_keeps_the_registry_stable(): void
    {
        $this->load();
        $snapshot = [
            Loop::query()->count(), Dossier::query()->count(), DossierFile::query()->count(),
            BlogPost::query()->count(), LoopMember::query()->count(), ScenarioPackEntity::query()->count(),
        ];
        $ids = DossierFile::query()->orderBy('path')->pluck('id')->all();

        $this->load();

        $this->assertSame($snapshot, [
            Loop::query()->count(), Dossier::query()->count(), DossierFile::query()->count(),
            BlogPost::query()->count(), LoopMember::query()->count(), ScenarioPackEntity::query()->count(),
        ]);
        $this->assertSame($ids, DossierFile::query()->orderBy('path')->pluck('id')->all(), 'Les memes lignes, pas une seconde copie.');
        $this->assertSame(1, ScenarioPackLoad::query()->count());
    }

    public function test_personas_are_registered_as_reused_and_everything_the_pack_creates_as_created(): void
    {
        $this->load();

        $entities = ScenarioPackEntity::query()->get();

        $this->assertSame(4, $entities->where('entity_type', 'persona')->count());
        $this->assertTrue($entities->where('entity_type', 'persona')->every(fn ($e) => $e->ownership === ScenarioPackEntity::OWNERSHIP_REUSED));

        foreach (['loop' => 10, 'loop_member' => 10, 'folder' => 10, 'root_document' => 10, 'folder_file' => 6] as $type => $count) {
            $this->assertSame($count, $entities->where('entity_type', $type)->count(), $type);
            $this->assertTrue($entities->where('entity_type', $type)->every(fn ($e) => $e->ownership === ScenarioPackEntity::OWNERSHIP_CREATED), "{$type} doit etre created");
        }
    }

    public function test_apply_refuses_any_other_organization_even_if_allowlisted(): void
    {
        $other = Organization::factory()->create(['slug' => 'artscilab-demo']);

        $this->expectException(LogicException::class);

        $pack = app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID);
        app(ScenarioPackLoader::class)->load($pack, $other);
    }

    public function test_load_is_refused_when_a_persona_is_missing_and_nothing_is_written(): void
    {
        $this->personas['test_sana']->forceDelete();

        try {
            $this->load();
            $this->fail('Le pack aurait du refuser.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('test_sana@bouclepro.test', $e->getMessage());
        }

        $this->assertSame(0, Loop::query()->count());
        $this->assertSame(0, DossierFile::query()->count());
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame([], Storage::disk(Test20260822DogfoodingPack::DISK)->allFiles(), 'Les fichiers ecrits avant le refus sont effaces.');
    }

    public function test_load_is_refused_when_the_source_directory_is_missing(): void
    {
        config([Test20260822DogfoodingPack::SOURCE_CONFIG_KEY => $this->source.'/nope']);

        $this->expectException(RuntimeException::class);

        $this->load();
    }

    // =====================================================================
    // E. delete / reset
    // =====================================================================

    public function test_removal_purges_loops_root_dossiers_root_documents_and_files_but_keeps_the_accounts(): void
    {
        $this->load();
        $paths = DossierFile::query()->pluck('path')->all();
        $this->assertCount(6, $paths);

        app(ScenarioPackRemover::class)->remove(Test20260822DogfoodingPack::PACK_ID, $this->organization);

        $this->assertSame(0, Loop::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, Dossier::query()->withTrashed()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, DossierFile::query()->withTrashed()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, BlogPost::query()->withTrashed()->where('organization_id', $this->organization->id)->count(), 'Aucun document racine orphelin.');
        $this->assertSame(0, LoopMember::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        foreach ($paths as $path) {
            Storage::disk(Test20260822DogfoodingPack::DISK)->assertMissing($path);
        }

        $this->assertSame(4, User::query()->where('organization_id', $this->organization->id)->count(), 'Les comptes du SuperAdmin restent.');
        $this->assertSame($this->personas['test_cyril']->id, $this->organization->fresh()->admin_id);
    }

    // =====================================================================
    // E. TASK-1274 — corpus DECLARE, jamais scanne
    // =====================================================================

    public function test_the_pack_declares_exactly_the_ten_corpus_directories_in_loop_order(): void
    {
        $declared = Test20260822DogfoodingPack::LOOP_DIRECTORIES;

        $this->assertCount(10, $declared);
        $this->assertSame(array_values(array_unique($declared)), $declared, 'Aucun doublon.');
        $this->assertNotContains('CV_profils', $declared, 'Les CV sont des sources factuelles, pas un corpus de Boucle.');

        $this->load();

        $this->assertSame(
            $declared,
            Loop::query()->where('organization_id', $this->organization->id)->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame(10, Loop::query()->where('organization_id', $this->organization->id)->count());
    }

    public function test_a_directory_present_on_disk_but_not_declared_is_ignored_without_any_rule_on_its_name(): void
    {
        // Le cas reel du 23/08 : `CV_profils/` depose a cote du corpus, avec
        // des fichiers dedans. Et un second annexe au nom quelconque, pour
        // prouver que la protection ne tient pas au nom `CV_profils`.
        File::makeDirectory($this->source.'/CV_profils', 0755, true);
        File::put($this->source.'/CV_profils/CV_Test_Cyril.pdf', '%PDF-1.4 fake');
        File::makeDirectory($this->source.'/11-Annexe future', 0755, true);
        File::put($this->source.'/11-Annexe future/note.md', "# Note\n");

        $this->load();

        $this->assertSame(10, Loop::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, Loop::query()->whereIn('name', ['CV_profils', '11-Annexe future'])->count());
        $this->assertSame(0, Dossier::query()->withTrashed()->whereIn('name', ['CV_profils', '11-Annexe future'])->count());
        $this->assertSame(0, DossierFile::query()->withTrashed()->whereIn('original_name', ['CV_Test_Cyril.pdf', 'note.md'])->count());
        $this->assertSame(6, DossierFile::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(10, ScenarioPackEntity::query()->where('entity_type', 'loop')->count());
        $this->assertFileExists($this->source.'/CV_profils/CV_Test_Cyril.pdf', 'Le pack ne touche jamais au disque source.');
    }

    public function test_a_declared_directory_missing_from_disk_fails_loudly_naming_it_and_loads_nothing(): void
    {
        $missing = Test20260822DogfoodingPack::LOOP_DIRECTORIES[3];
        File::deleteDirectory($this->source.'/'.$missing);

        try {
            $this->load();
            $this->fail('Un corpus incomplet doit echouer bruyamment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($missing, $e->getMessage(), 'L\'exception nomme le repertoire absent.');
        }

        $this->assertSame(0, Loop::query()->count(), 'Transaction annulee : rien n\'est charge.');
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    public function test_reset_reapplies_without_error_and_purges_a_file_that_disappeared_from_the_source(): void
    {
        $this->load();
        File::delete($this->source.'/'.self::LOOP_A.'/02-Notes.txt');

        $result = app(ScenarioPackResetter::class)->reset(
            app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID),
            $this->organization,
        );

        $this->assertSame(5, DossierFile::query()->withTrashed()->count());
        $this->assertSame(0, DossierFile::query()->where('original_name', '02-Notes.txt')->count());
        $this->assertSame(
            ['folder_file|'.Str::slug(self::LOOP_A).'/'.Test20260822DogfoodingPack::storedName('02-Notes.txt')],
            $result->removedOrphans,
            'Seul l\'orphelin est retire : les cles des autres fichiers ne dependent pas de la position.'
        );
        $this->assertSame(10, Loop::query()->count());
        $this->assertSame('02-notes-'.substr(hash('sha256', '02-Notes.txt'), 0, 6).'.txt', Test20260822DogfoodingPack::storedName('02-Notes.txt'));
    }
}
