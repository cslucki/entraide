<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\AdminAiInteraction;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiCorrelation;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1133 / IA P1-3 — instrumentation des invocations Embeddings du Laravel
 * AI SDK. Preuve d'intégration : le chemin Dossiers, seul usage réel du SDK.
 */
class TASK1133SdkEmbeddingsInstrumentationTest extends TestCase
{
    public function test_an_operation_keeps_its_correlation_id_through_the_write(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();
        $correlationId = AiCorrelation::start();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $row = AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')->firstOrFail();
        $this->assertSame($correlationId, $row->correlation_id);
    }

    public function test_an_sdk_invocation_has_its_own_invocation_id(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $row = AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')->firstOrFail();
        $invocationId = $row->metadata['sdk_invocation_id'] ?? null;

        $this->assertIsString($invocationId);
        $this->assertTrue(Str::isUuid($invocationId));
    }

    public function test_two_invocations_of_the_same_operation_share_one_correlation(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $secondPost = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'TASK1133 second article '.Str::uuid(),
            'slug' => 'task1133-second-article-'.Str::uuid(),
            'content' => '<p>second searchable article content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $secondPost->id,
            'added_by' => $owner->id,
            'position' => 2,
        ]);
        $this->enableGate($organization);
        $this->fakeEmbeddings();
        $correlationId = AiCorrelation::start();

        // Une seule opération BouclePro (une même corrélation liée à la
        // volée) qui déclenche deux invocations SDK distinctes : deux
        // articles indexés dans le même Dossier sous la même intention.
        // Le couple index+recherche réel n'est exercé que sous PostgreSQL
        // (pgvector) — voir Pgvector*Test — donc pas reproduit ici en SQLite.
        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);
        $this->indexer()->synchronize($organization->id, $dossier->id, $secondPost->id);

        $rows = AdminAiInteraction::where('correlation_id', $correlationId)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertNotSame(
            $rows[0]->metadata['sdk_invocation_id'],
            $rows[1]->metadata['sdk_invocation_id'],
        );
    }

    public function test_two_distinct_operations_do_not_share_a_correlation(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $firstCorrelationId = AiCorrelation::start();
        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);
        AiCorrelation::forget();

        // Editer le contenu declenche automatiquement un second cycle
        // d'indexation via BlogPostObserver::updated() ->
        // DossierArticleIndexingDispatcher (QUEUE_CONNECTION=sync en test,
        // donc job execute immediatement) : une operation BouclePro
        // distincte, sans corrélation liee a la volee au prealable — le job
        // en etablit donc une nouvelle a son DISPATCH.
        $post->update(['content' => '<p>changed so a new batch is produced</p>']);

        $correlations = AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')
            ->orderBy('created_at')
            ->pluck('correlation_id');

        $this->assertCount(2, $correlations);
        $this->assertSame($firstCorrelationId, $correlations[0]);
        $this->assertNotSame($correlations[0], $correlations[1]);
    }

    public function test_two_organizations_stay_strictly_separated(): void
    {
        [$organizationA, , $dossierA, $postA] = $this->eligibleFixture(sentinel: 'SENTINEL-ORG-A');
        [$organizationB, , $dossierB, $postB] = $this->eligibleFixture(sentinel: 'SENTINEL-ORG-B');
        $this->enableGate($organizationA);
        $this->enableGate($organizationB);
        $this->fakeEmbeddings();

        AiCorrelation::start();
        $this->indexer()->synchronize($organizationA->id, $dossierA->id, $postA->id);

        // Deux operations BouclePro distinctes methodologiquement : sans ce
        // forget(), les deux appels partageraient la corrélation lazily
        // creee par le premier — correct pour AiCorrelation (une operation
        // non delimitee explicitement reste une seule operation), mais ce
        // n'est pas ce que ce test veut demontrer (la separation stricte par
        // Organization, pas la separation de corrélation).
        AiCorrelation::forget();
        AiCorrelation::start();
        $this->indexer()->synchronize($organizationB->id, $dossierB->id, $postB->id);

        $rowsA = AdminAiInteraction::where('organization_id', $organizationA->id)->get();
        $rowsB = AdminAiInteraction::where('organization_id', $organizationB->id)->get();

        $this->assertCount(1, $rowsA);
        $this->assertCount(1, $rowsB);
        $this->assertSame($dossierA->id, $rowsA[0]->metadata['dossier_id']);
        $this->assertSame($dossierB->id, $rowsB[0]->metadata['dossier_id']);
        $this->assertNotSame($rowsA[0]->correlation_id, $rowsB[0]->correlation_id);
    }

    public function test_a_faked_embedding_call_triggers_the_expected_instrumentation(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $row = AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')->firstOrFail();
        $this->assertSame('success', $row->status);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('text-embedding-3-small', $row->model);
        $this->assertGreaterThan(0, $row->input_tokens);
        $this->assertSame(0, $row->output_tokens);
        $this->assertIsInt($row->latency_ms);
        $this->assertGreaterThanOrEqual(0, $row->latency_ms);
    }

    public function test_no_double_counting_for_a_single_batch_call(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture(content: $this->words(620));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        // Contenu long => plusieurs chunks, un seul appel SDK batché.
        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertGreaterThan(
            1,
            DossierChunk::query()->where('blog_post_id', $post->id)->count(),
            'La fixture doit produire plusieurs chunks pour prouver le non doublon.',
        );
        $this->assertSame(1, AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')->count());
    }

    public function test_sdk_error_is_faithfully_represented(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->configureEmbeddingsProvider();
        Embeddings::fake(fn (): array => throw new RuntimeException('Provider unavailable.'))
            ->preventStrayEmbeddings();

        try {
            $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);
            $this->fail('Expected the provider exception to propagate unchanged.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider unavailable.', $exception->getMessage());
        }

        $row = AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->cost_usd);
        $this->assertTrue($row->cost_unknown);
        $this->assertSame(0, $row->input_tokens);
    }

    public function test_instrumentation_does_not_change_the_functional_output(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture(content: $this->words(620));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(2, $count);
        $this->assertSame(
            2,
            DossierChunk::query()->where('blog_post_id', $post->id)->count(),
        );

        // La recherche pgvector reelle n'est jouee que sous PostgreSQL (voir
        // Pgvector*Test) : sous SQLite, le service leve toujours son
        // exception explicite documentee, avant que l'instrumentation
        // n'intervienne (elle est posee juste apres cette garde). Prouver ici
        // que ce comportement reste EXACTEMENT le meme qu'avant TASK-1133.
        if (config('database.default') === 'pgsql') {
            $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');
            $this->assertIsArray($results);

            return;
        }

        try {
            app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');
            $this->fail('Expected the PostgreSQL-required exception to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Dossier semantic search requires PostgreSQL pgvector.', $exception->getMessage());
        }
    }

    public function test_async_correlation_propagates_from_dispatch_to_job_execution(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $job = new IndexDossierArticleChunks($organization->id, $dossier->id, $post->id);
        $dispatchCorrelationId = $job->correlationId;

        AiCorrelation::forget();
        $job->handle(app(DossierArticleIndexer::class));

        $row = AdminAiInteraction::where('scenario_id', 'dossier_embeddings_index')->firstOrFail();
        $this->assertSame($dispatchCorrelationId, $row->correlation_id);
    }

    private function indexer(): DossierArticleIndexer
    {
        return app(DossierArticleIndexer::class);
    }

    /**
     * @return array{0: Organization, 1: User, 2: Dossier, 3: BlogPost}
     */
    private function eligibleFixture(string $content = '<p>searchable article content</p>', string $sentinel = ''): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1133 dossier '.($sentinel !== '' ? $sentinel : Str::uuid()),
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'TASK1133 article '.Str::uuid(),
            'slug' => 'task1133-article-'.Str::uuid(),
            'content' => $content,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return [$organization, $owner, $dossier, $post];
    }

    private function enableGate(Organization $organization): void
    {
        $ids = array_unique(array_merge(
            config('ai.dossiers.semantic_search.organization_ids', []),
            [$organization->id],
        ));

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', $ids);
    }

    private function configureEmbeddingsProvider(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', $this->fakeEmbeddingDimensions());
    }

    private function fakeEmbeddings(): void
    {
        $this->configureEmbeddingsProvider();
        $dimensions = $this->fakeEmbeddingDimensions();

        // Retourne une vraie EmbeddingsResponse (pas un tableau de vecteurs
        // brut) : FakeEmbeddingGateway::marshalResponse() fabrique sinon
        // `tokens = 0` en dur, ce qui rendrait la mesure d'usage invérifiable
        // dans ces tests.
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($dimensions): EmbeddingsResponse {
            $vectors = array_map(
                fn (): array => array_fill(0, $dimensions, 0.1),
                $prompt->inputs,
            );

            return new EmbeddingsResponse(
                $vectors,
                count($prompt->inputs) * 3,
                new Meta($prompt->provider->name(), $prompt->model),
            );
        })->preventStrayEmbeddings();
    }

    private function fakeEmbeddingDimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }

    private function words(int $count, string $prefix = 'word'): string
    {
        return '<p>'.implode(' ', array_map(fn (int $index): string => $prefix.$index, range(1, $count))).'</p>';
    }
}
