<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Dossiers\OrganizationRagOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\Data\Meta;
use RuntimeException;
use Tests\TestCase;

/**
 * Observatoire vivant des connaissances Organization V1 (TASK-1226).
 *
 * Nouveaux contrats proteges ici, en plus des invariants TASK-1217 (qui
 * restent dans TASK1217RagConsoleTest) :
 * - le PERIMETRE d'une source est derive de la racine gouvernante de son
 *   Dossier (loop_id / visibility / shared_with_loop_id), jamais du nom ;
 * - les cartes de perimetre agregent sources et extraits par espace ;
 * - l'endpoint de rafraichissement est strictement tenant, admin-only,
 *   read-only : aucun appel IA, aucun secret, aucun contenu RAG ;
 * - l'etat de l'infrastructure (credential, budget, activation) est affiche
 *   au niveau Organization, AU PRESENT, jamais impute a une source ;
 * - aucun etat pending / failed / obsolete n'est fabrique.
 */
class TASK1226KnowledgeObservatoryTest extends TestCase
{
    use RefreshDatabase;

    private function dimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }

    // ---- Perimetres ----

    public function test_the_scope_of_a_source_comes_from_the_governing_dossier_never_from_its_name(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $loop = $this->loop($organization, $admin, 'ARIA');
        $someone = User::factory()->create(['organization_id' => $organization->id]);

        // Noms volontairement trompeurs : seule la donnee compte.
        $loopRoot = $this->loopDossier($organization, $loop, 'Privé de Paul');
        $loopChild = $this->childDossier($organization, $loopRoot, 'Organization — tout le monde');
        $orgWide = $this->dossier($organization, $someone, 'Boucle ARIA — notes', Dossier::VISIBILITY_ORGANIZATION);
        $private = $this->dossier($organization, $someone, 'ARIA partage', Dossier::VISIBILITY_PRIVATE);
        $sharedWithLoop = $this->dossier($organization, $someone, 'Mes trucs', Dossier::VISIBILITY_LOOP, $loop);

        $this->file($organization, $loopChild, $admin, 'enfant.txt', 'text/plain');
        $this->file($organization, $orgWide, $someone, 'org.txt', 'text/plain');
        $this->file($organization, $private, $someone, 'prive.txt', 'text/plain');
        $this->file($organization, $sharedWithLoop, $someone, 'partage.md', 'text/markdown');

        $sources = collect(app(OrganizationRagOverview::class)->sources($organization->id))->keyBy('title');

        $this->assertSame(OrganizationRagOverview::SCOPE_LOOP, $sources['enfant.txt']['scope']['kind'], 'un enfant herite du perimetre de sa racine gouvernante');
        $this->assertSame((string) $loop->id, $sources['enfant.txt']['scope']['loop_id']);
        $this->assertSame('ARIA', $sources['enfant.txt']['scope']['loop_name']);

        $this->assertSame(OrganizationRagOverview::SCOPE_ORGANIZATION, $sources['org.txt']['scope']['kind']);
        $this->assertNull($sources['org.txt']['scope']['loop_id']);

        $this->assertSame(OrganizationRagOverview::SCOPE_PRIVATE, $sources['prive.txt']['scope']['kind']);

        $this->assertSame(OrganizationRagOverview::SCOPE_LOOP_SHARED, $sources['partage.md']['scope']['kind']);
        $this->assertSame((string) $loop->id, $sources['partage.md']['scope']['loop_id']);
        $this->assertSame('ARIA', $sources['partage.md']['scope']['loop_name']);
    }

    public function test_a_loop_visibility_without_a_valid_loop_of_this_organization_is_private(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        [$otherOrg, $otherAdmin] = $this->organizationWithAdmin();
        $foreignLoop = $this->loop($otherOrg, $otherAdmin, 'Boucle étrangère');

        $orphan = $this->dossier($organization, $admin, 'Sans Boucle', Dossier::VISIBILITY_LOOP);
        $foreign = $this->dossier($organization, $admin, 'Boucle d’ailleurs', Dossier::VISIBILITY_LOOP, $foreignLoop);
        $this->file($organization, $orphan, $admin, 'orphan.txt', 'text/plain');
        $this->file($organization, $foreign, $admin, 'foreign.txt', 'text/plain');

        $sources = collect(app(OrganizationRagOverview::class)->sources($organization->id))->keyBy('title');

        // Personne d'autre que le proprietaire ne peut voir ces Dossiers :
        // les presenter comme « Boucle » serait faux, et nommer une Boucle
        // d'une autre Organization serait une fuite.
        $this->assertSame(OrganizationRagOverview::SCOPE_PRIVATE, $sources['orphan.txt']['scope']['kind']);
        $this->assertSame(OrganizationRagOverview::SCOPE_PRIVATE, $sources['foreign.txt']['scope']['kind']);
        $this->assertNull($sources['foreign.txt']['scope']['loop_name']);
    }

    public function test_perimeter_cards_aggregate_sources_and_chunks_per_scope(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $aria = $this->loop($organization, $admin, 'ARIA');
        $emergence = $this->loop($organization, $admin, 'Emergence');

        $ariaRoot = $this->loopDossier($organization, $aria, 'ARIA — Docs');
        $emergenceRoot = $this->loopDossier($organization, $emergence, 'Emergence — Docs');
        $sharedWithAria = $this->dossier($organization, $admin, 'Perso partagé', Dossier::VISIBILITY_LOOP, $aria);
        $orgWide = $this->dossier($organization, $admin, 'Commun', Dossier::VISIBILITY_ORGANIZATION);
        $private = $this->dossier($organization, $admin, 'Perso', Dossier::VISIBILITY_PRIVATE);

        $a1 = $this->file($organization, $ariaRoot, $admin, 'a1.txt', 'text/plain');
        $a2 = $this->file($organization, $ariaRoot, $admin, 'a2.txt', 'text/plain');
        $a3 = $this->file($organization, $sharedWithAria, $admin, 'a3.txt', 'text/plain');
        $e1 = $this->file($organization, $emergenceRoot, $admin, 'e1.txt', 'text/plain');
        $o1 = $this->file($organization, $orgWide, $admin, 'o1.txt', 'text/plain');
        $this->file($organization, $orgWide, $admin, 'o2.txt', 'text/plain');
        $p1 = $this->file($organization, $private, $admin, 'p1.txt', 'text/plain');

        $this->chunkForFile($organization, $ariaRoot, $a1);
        $this->chunkForFile($organization, $ariaRoot, $a1, 1);
        $this->chunkForFile($organization, $ariaRoot, $a2);
        $this->chunkForFile($organization, $sharedWithAria, $a3);
        $this->chunkForFile($organization, $emergenceRoot, $e1);
        $this->chunkForFile($organization, $orgWide, $o1);
        $this->chunkForFile($organization, $private, $p1);
        $this->chunkForFile($organization, $private, $p1, 1);
        $this->chunkForFile($organization, $private, $p1, 2);

        $overview = app(OrganizationRagOverview::class);
        $perimeters = $overview->perimeters($overview->sources($organization->id));

        $this->assertSame(['sources' => 2, 'chunks' => 1], $perimeters['organization']);
        $this->assertSame(['sources' => 1, 'chunks' => 3], $perimeters['private']);

        $loops = collect($perimeters['loops'])->keyBy('name');
        $this->assertSame(3, $loops['ARIA']['sources']);
        $this->assertSame(4, $loops['ARIA']['chunks']);
        $this->assertSame(2, $loops['ARIA']['owned_sources']);
        $this->assertSame(1, $loops['ARIA']['shared_sources'], 'un Dossier personnel partage avec la Boucle est montre comme tel');
        $this->assertSame(1, $loops['Emergence']['sources']);
        $this->assertSame(1, $loops['Emergence']['chunks']);

        // Aucun connecteur externe n'existe : rien n'est invente.
        $this->assertFalse($perimeters['external']['connected']);
        $this->assertSame(0, $perimeters['external']['sources']);
    }

    // ---- Formats / apparition ----

    public function test_files_expose_their_real_format_from_mime_and_extension(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');
        $this->file($organization, $dossier, $admin, 'guide.md', 'text/plain'); // .md arrive parfois en text/plain
        $this->file($organization, $dossier, $admin, 'readme', 'text/markdown');
        $this->attachedArticle($organization, $dossier, $admin, 'Un article');

        $sources = collect(app(OrganizationRagOverview::class)->sources($organization->id))->keyBy('title');

        $this->assertSame('txt', $sources['notes.txt']['format']);
        $this->assertSame('markdown', $sources['guide.md']['format']);
        $this->assertSame('markdown', $sources['readme']['format']);
        $this->assertSame('article', $sources['Un article']['format']);
    }

    public function test_a_source_carries_the_moment_it_appeared_in_its_dossier(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $file = $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');
        $this->attachedArticle($organization, $dossier, $admin, 'Un article');

        $sources = collect(app(OrganizationRagOverview::class)->sources($organization->id))->keyBy('title');

        $this->assertNotNull($sources['notes.txt']['created_at']);
        $this->assertSame($file->created_at->format('Y-m-d H:i:s'), \Illuminate\Support\Carbon::parse($sources['notes.txt']['created_at'])->format('Y-m-d H:i:s'));
        $this->assertNotNull($sources['Un article']['created_at']);
    }

    public function test_the_summary_exposes_the_corpus_volume_without_reading_any_content(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        [$orgB, $adminB] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $file = $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');
        $this->chunkForFile($organization, $dossier, $file, 0, tokens: 40, content: 'abcd');
        $this->chunkForFile($organization, $dossier, $file, 1, tokens: 60, content: 'efghij');

        $dossierB = $this->dossier($orgB, $adminB, 'B', Dossier::VISIBILITY_ORGANIZATION);
        $fileB = $this->file($orgB, $dossierB, $adminB, 'b.txt', 'text/plain');
        $this->chunkForFile($orgB, $dossierB, $fileB, 0, tokens: 999, content: 'zzzzzzzzzz');

        $summary = app(OrganizationRagOverview::class)->summary($organization->id);

        $this->assertSame(2, $summary['chunks']);
        $this->assertSame(100, $summary['corpus_tokens']);
        $this->assertSame(10, $summary['corpus_characters']);
    }

    // ---- Etat de l'infrastructure (niveau Organization, au present) ----

    public function test_indexing_availability_reports_the_missing_credential_at_organization_level(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        // Aucune configuration IA : aucun embedding tenant possible.

        $availability = app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());

        $this->assertTrue($availability['semantic_search_enabled']);
        $this->assertFalse($availability['embedding_credential_available']);
        $this->assertTrue($availability['budget_allows_indexing']);
        $this->assertFalse($availability['available']);
    }

    public function test_indexing_availability_reports_an_exhausted_budget_at_organization_level(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1226-tenant',
            'monthly_budget_usd' => 0.00,
        ]);

        $availability = app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());

        $this->assertTrue($availability['embedding_credential_available']);
        $this->assertFalse($availability['budget_allows_indexing']);
        $this->assertNotNull($availability['budget_reason']);
        $this->assertFalse($availability['available']);
    }

    public function test_indexing_availability_is_available_when_everything_is_in_place(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1226-tenant',
            'monthly_budget_usd' => 10.00,
        ]);

        $availability = app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());

        $this->assertTrue($availability['available']);
        $this->assertNull($availability['budget_reason']);
    }

    public function test_a_disabled_semantic_search_is_reported_not_guessed(): void
    {
        [$organization] = $this->organizationWithAdmin();
        config()->set('ai.dossiers.semantic_search.enabled', false);

        $availability = app(OrganizationRagOverview::class)->indexingAvailability($organization->fresh());

        $this->assertFalse($availability['semantic_search_enabled']);
        $this->assertFalse($availability['available']);
    }

    // ---- Page ----

    public function test_the_page_presents_the_observatory_with_its_live_container(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $response = $this->actingAs($admin)->get($this->pageUrl($organization));

        $response->assertOk();
        $response->assertSee(__('ai.observatory_title'));
        $response->assertSee('data-knowledge-live', false);
        $response->assertSee($this->liveUrl($organization), false);
        $response->assertSee(__('ai.observatory_auto_refresh'));
        $response->assertSee(__('ai.observatory_refresh_now'));
    }

    // ---- Endpoint de rafraichissement ----

    public function test_the_live_fragment_is_admin_only_and_tenant_strict(): void
    {
        [$orgA, $adminA] = $this->organizationWithAdmin();
        [$orgB, $adminB] = $this->organizationWithAdmin();
        $memberA = User::factory()->create(['organization_id' => $orgA->id]);

        $dossierA = $this->dossier($orgA, $adminA, 'Dossier A', Dossier::VISIBILITY_ORGANIZATION);
        $this->file($orgA, $dossierA, $adminA, 'visible-a.txt', 'text/plain');
        $loopB = $this->loop($orgB, $adminB, 'Boucle secrète B');
        $dossierB = $this->loopDossier($orgB, $loopB, 'Dossier secret B');
        $fileB = $this->file($orgB, $dossierB, $adminB, 'secret-b.txt', 'text/plain');
        $this->chunkForFile($orgB, $dossierB, $fileB);

        $this->actingAs($memberA)->get($this->liveUrl($orgA))->assertForbidden();
        $this->actingAs($adminB)->get($this->liveUrl($orgA))->assertForbidden();

        $response = $this->actingAs($adminA)->get($this->liveUrl($orgA));
        $response->assertOk();
        $response->assertSee('visible-a.txt');
        $response->assertDontSee('secret-b.txt');
        $response->assertDontSee('Dossier secret B');
        $response->assertDontSee('Boucle secrète B');
        $response->assertHeader('Cache-Control', 'no-cache, no-store, private');
    }

    public function test_the_live_fragment_never_exposes_a_secret_a_path_or_rag_content(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1226-very-secret-key',
        ]);
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $file = $this->file($organization, $dossier, $admin, 'notes.txt', 'text/plain');
        $this->chunkForFile($organization, $dossier, $file, 0, content: 'SENTINELLE-CONTENU-RAG-1226');

        $html = $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk()->getContent();

        $this->assertStringNotContainsString('sk-task1226', $html);
        $this->assertStringNotContainsString('SENTINELLE-CONTENU-RAG-1226', $html);
        $this->assertStringNotContainsString($file->path, $html);
        $this->assertStringContainsString('notes.txt', $html);
    }

    public function test_reading_the_observatory_triggers_no_ai_call_and_no_job(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1226-tenant',
        ]);
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $this->file($organization, $dossier, $admin, 'jamais-indexe.txt', 'text/plain');

        Queue::fake();
        Embeddings::fake(function (): never {
            throw new RuntimeException('Reading the observatory must never embed anything.');
        })->preventStrayEmbeddings();

        $this->actingAs($admin)->get($this->pageUrl($organization))->assertOk();
        $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk();
        $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk();

        Embeddings::assertNothingGenerated();
        Queue::assertNothingPushed();
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'lire l’Observatoire coute 0 appel IA');
    }

    public function test_the_live_fragment_reflects_a_source_that_becomes_indexed(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $file = $this->file($organization, $dossier, $admin, 'roger.md', 'text/markdown');

        $before = $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk()->getContent();
        $this->assertStringContainsString('data-source-key="file:'.$file->id.'"', $before);
        $this->assertStringContainsString('data-source-indexed="0"', $before);
        $this->assertStringContainsString('data-source-chunks="0"', $before);
        $this->assertStringContainsString(__('ai.knowledge_console_state_not_indexed'), $before);

        $this->chunkForFile($organization, $dossier, $file, 0);
        $this->chunkForFile($organization, $dossier, $file, 1);

        $after = $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk()->getContent();
        $this->assertStringContainsString('data-source-indexed="1"', $after);
        $this->assertStringContainsString('data-source-chunks="2"', $after);
        $this->assertStringContainsString(__('ai.knowledge_console_state_indexed'), $after);
    }

    public function test_the_open_link_in_the_live_fragment_follows_dossier_policy_only(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $someone = User::factory()->create(['organization_id' => $organization->id]);
        $private = $this->dossier($organization, $someone, 'Privé de quelqu’un', Dossier::VISIBILITY_PRIVATE);
        $shared = $this->dossier($organization, $someone, 'Commun', Dossier::VISIBILITY_ORGANIZATION);
        $privateFile = $this->file($organization, $private, $someone, 'prive.txt', 'text/plain');
        $sharedFile = $this->file($organization, $shared, $someone, 'commun.txt', 'text/plain');
        $this->chunkForFile($organization, $private, $privateFile);

        $html = $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk()->getContent();

        // L'etat du Dossier prive est visible…
        $this->assertStringContainsString('prive.txt', $html);
        $this->assertStringContainsString('data-source-key="file:'.$privateFile->id.'" data-source-indexed="1"', $html);
        // …mais son contenu n'est pas ouvrable, alors que le Dossier commun l'est.
        $this->assertStringNotContainsString(route('organization.dossiers.files.show', ['organization' => $organization->slug, 'dossier' => $private->id, 'file' => $privateFile->id]), $html);
        $this->assertStringContainsString(route('organization.dossiers.files.show', ['organization' => $organization->slug, 'dossier' => $shared->id, 'file' => $sharedFile->id]), $html);
    }

    // ---- Verite des etats ----

    public function test_no_pending_failed_or_stale_state_is_ever_fabricated(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier', Dossier::VISIBILITY_ORGANIZATION);
        $indexed = $this->file($organization, $dossier, $admin, 'indexe.txt', 'text/plain');
        $this->file($organization, $dossier, $admin, 'jamais.txt', 'text/plain');
        $this->chunkForFile($organization, $dossier, $indexed);
        // Le fichier indexe change ensuite de contenu (checksum) : sans
        // relecture, rien ne prouve une version obsolete — rien n'est affiche.
        $indexed->forceFill(['checksum_sha256' => hash('sha256', 'nouveau contenu')])->saveQuietly();

        $sources = app(OrganizationRagOverview::class)->sources($organization->id);
        foreach ($sources as $source) {
            foreach (['pending', 'processing', 'failed', 'stale', 'status', 'error'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $source, "aucune cle « {$forbidden} » : cet etat n’est pas prouvable");
            }
            $this->assertIsBool($source['indexed']);
        }

        $html = $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk()->getContent();
        foreach (['obsolète', 'En attente', 'En cours', 'Échec', 'Erreur'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    // ---- Performance ----

    public function test_the_query_count_of_the_live_fragment_does_not_grow_with_the_number_of_sources(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $loop = $this->loop($organization, $admin, 'ARIA');
        $dossiers = [
            $this->loopDossier($organization, $loop, 'ARIA — Docs'),
            $this->dossier($organization, $admin, 'Commun', Dossier::VISIBILITY_ORGANIZATION),
            $this->dossier($organization, $admin, 'Perso', Dossier::VISIBILITY_PRIVATE),
            $this->dossier($organization, $admin, 'Partagé', Dossier::VISIBILITY_LOOP, $loop),
        ];

        $seed = function (int $perDossier) use ($organization, $admin, $dossiers): void {
            foreach ($dossiers as $dossier) {
                for ($i = 0; $i < $perDossier; $i++) {
                    $file = $this->file($organization, $dossier, $admin, Str::uuid().'.txt', 'text/plain');
                    $this->chunkForFile($organization, $dossier, $file);
                }
            }
        };

        $seed(2);
        $small = $this->countQueries(fn () => $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk());

        $seed(10);
        $large = $this->countQueries(fn () => $this->actingAs($admin)->get($this->liveUrl($organization))->assertOk());

        $this->assertSame($small, $large, "le nombre de requetes ne doit pas dependre du nombre de sources ({$small} vs {$large})");
        $this->assertLessThan(60, $large, 'un poll doit rester bon marche');
    }

    // ---- Cross-tenant retrieval (PostgreSQL reel) ----

    public function test_a_sentinel_chunk_of_organization_a_is_never_returned_for_organization_b(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Semantic search requires PostgreSQL pgvector.');
        }

        [$orgA, $adminA] = $this->organizationWithAdmin();
        [$orgB, $adminB] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($orgA, $orgB);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.driver', 'openai');
        config()->set('ai.providers.openai.key', 'platform-key-test');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', 1536);

        $dossierA = $this->dossier($orgA, $adminA, 'Dossier A', Dossier::VISIBILITY_ORGANIZATION);
        $fileA = $this->file($orgA, $dossierA, $adminA, 'smart-village.md', 'text/markdown');
        $this->chunkForFile($orgA, $dossierA, $fileA, 0, content: 'Code de demonstration : ROGER-SMART-VILLAGE-1226.');
        $dossierB = $this->dossier($orgB, $adminB, 'Dossier B', Dossier::VISIBILITY_ORGANIZATION);

        // La requete de B est encodee EXACTEMENT comme le chunk de A :
        // distance nulle, le meilleur candidat possible.
        Embeddings::fake(function (EmbeddingsPrompt $prompt): EmbeddingsResponse {
            $vectors = array_map(fn (): array => array_fill(0, 1536, 0.1), $prompt->inputs);

            return new EmbeddingsResponse($vectors, 3, new Meta($prompt->provider->name(), $prompt->model));
        })->preventStrayEmbeddings();

        $search = app(DossierSemanticSearchService::class);

        // Meme en passant l'identifiant du Dossier de A, le tenant B ne voit rien.
        $this->assertSame([], $search->searchAcrossDossiers($orgB->id, [$dossierA->id, $dossierB->id], 'code de demonstration', 5));
        $this->assertSame([], $search->search($orgB->id, $dossierA->id, 'code de demonstration', 5));

        // Et A, lui, retrouve bien sa sentinelle.
        $rowsA = $search->searchAcrossDossiers($orgA->id, [$dossierA->id], 'code de demonstration', 5);
        $this->assertCount(1, $rowsA);
        $this->assertStringContainsString('ROGER-SMART-VILLAGE-1226', $rowsA[0]['content']);
    }

    // ---- helpers ----

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $admin->id]);

        return [$organization->fresh(), $admin];
    }

    private function enableSemanticSearchFor(Organization ...$organizations): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', array_map(fn (Organization $o): string => (string) $o->id, $organizations));
    }

    private function pageUrl(Organization $organization): string
    {
        return route('organization.admin.ai-knowledge', ['organization' => $organization->slug]);
    }

    private function liveUrl(Organization $organization): string
    {
        return route('organization.admin.ai-knowledge.live', ['organization' => $organization->slug]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function loop(Organization $organization, User $creator, string $name): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'created_by' => $creator->id,
        ]);

        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id,
            'user_id' => $creator->id,
            'organization_id' => $organization->id,
        ]);

        return $loop;
    }

    private function loopDossier(Organization $organization, Loop $loop, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'loop_id' => $loop->id,
            'name' => $name,
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
    }

    private function childDossier(Organization $organization, Dossier $parent, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'parent_id' => $parent->id,
            'name' => $name,
            'visibility' => $parent->visibility,
        ]);
    }

    private function dossier(Organization $organization, User $owner, string $name, string $visibility = Dossier::VISIBILITY_PRIVATE, ?Loop $sharedWith = null): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
            'shared_with_loop_id' => $sharedWith?->id,
        ]);
    }

    private function attachedArticle(Organization $organization, Dossier $dossier, User $author, string $title): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::uuid(),
            'content' => '<p>'.$title.'</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'listed_in_blog' => true,
        ]);

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $author->id,
            'position' => 1,
        ]);

        return $post;
    }

    private function file(Organization $organization, Dossier $dossier, User $uploader, string $name, string $mime): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $uploader->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::uuid().'-'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => 42,
            'checksum_sha256' => hash('sha256', $name.Str::uuid()),
            'source' => 'upload',
        ]);
    }

    private function chunkForFile(Organization $organization, Dossier $dossier, DossierFile $file, int $chunkIndex = 0, int $tokens = 3, string $content = 'contenu fichier'): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'content_hash' => hash('sha256', $file->id.$chunkIndex),
            'token_count' => $tokens,
            'embedding' => array_fill(0, $this->dimensions(), 0.1),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }
}
