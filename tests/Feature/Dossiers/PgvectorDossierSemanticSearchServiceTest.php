<?php

namespace Tests\Feature\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class PgvectorDossierSemanticSearchServiceTest extends TestCase
{
    public function test_closest_result_is_ranked_first_and_result_shape_is_stable(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $this->fakeQueryEmbedding();
        $far = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.5), 'Far chunk');
        $near = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.0), 'Near chunk');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame($near->blog_post_id, $results[0]['blog_post_id']);
        $this->assertSame($far->blog_post_id, $results[1]['blog_post_id']);
        $this->assertSame(['blog_post_id', 'title', 'slug', 'chunk_index', 'content', 'distance'], array_keys($results[0]));
        $this->assertSame(0, $results[0]['chunk_index']);
        $this->assertSame('Near chunk', $results[0]['content']);
        $this->assertIsFloat($results[0]['distance']);
        $this->assertOneQueryEmbeddingGenerated();
    }

    public function test_multiple_distances_are_ordered_exactly_by_pgvector(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $this->fakeQueryEmbedding();
        $third = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.4), 'Third');
        $first = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.0), 'First');
        $second = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.2), 'Second');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([
            $first->blog_post_id,
            $second->blog_post_id,
            $third->blog_post_id,
        ], array_column($results, 'blog_post_id'));
    }

    public function test_top_five_maximum_is_returned(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $this->fakeQueryEmbedding();

        for ($index = 0; $index < 7; $index++) {
            $this->attachedChunk($organization, $dossier, $owner, $this->vector($index / 10), "Chunk {$index}");
        }

        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 5);

        $this->assertCount(5, $results);
    }

    public function test_organization_id_filter_excludes_other_organization_chunks(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        [$otherOrganization, $otherDossier, $otherOwner] = $this->fixture();
        $this->fakeQueryEmbedding();
        $expected = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.3), 'Expected');
        $this->attachedChunk($otherOrganization, $otherDossier, $otherOwner, $this->vector(0.0), 'Other org');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([$expected->blog_post_id], array_column($results, 'blog_post_id'));
    }

    public function test_dossier_id_filter_excludes_chunks_from_same_organization_other_dossier(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $otherDossier = $this->createDossier($organization, $owner);
        $this->fakeQueryEmbedding();
        $expected = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.3), 'Expected');
        $this->attachedChunk($organization, $otherDossier, $owner, $this->vector(0.0), 'Other dossier');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([$expected->blog_post_id], array_column($results, 'blog_post_id'));
    }

    public function test_other_dossier_is_excluded_even_when_chunk_is_stale(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $otherDossier = $this->createDossier($organization, $owner);
        $this->fakeQueryEmbedding();
        $post = $this->createBlogPost($organization, $owner);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $otherDossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);
        $this->createChunk($organization, $dossier, $post, $this->vector(0.0), 'Stale wrong dossier');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([], $results);
    }

    public function test_draft_future_soft_deleted_detached_and_deleted_dossier_content_is_excluded(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $deletedDossier = $this->createDossier($organization, $owner);
        $this->fakeQueryEmbedding();

        $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.0), 'Draft', ['status' => 'draft']);
        $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.1), 'Future', ['published_at' => now()->addDay()]);
        $deletedPostChunk = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.2), 'Deleted post');
        $deletedPostChunk->blogPost->delete();
        $detachedChunk = $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.3), 'Detached');
        DossierBlogPost::query()->where('blog_post_id', $detachedChunk->blog_post_id)->delete();
        $this->attachedChunk($organization, $deletedDossier, $owner, $this->vector(0.4), 'Deleted dossier');
        $deletedDossier->delete();
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([], $results);
    }

    public function test_soft_deleted_target_dossier_returns_no_results_without_embeddings(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        Embeddings::fake()->preventStrayEmbeddings();
        $this->attachedChunk($organization, $dossier, $owner, $this->vector(0.0), 'Deleted target dossier');
        $dossier->delete();
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([], $results);
        Embeddings::assertNothingGenerated();
    }

    public function test_old_embedding_model_is_excluded(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $this->fakeQueryEmbedding();
        $post = $this->createBlogPost($organization, $owner);
        $this->attachPost($organization, $dossier, $owner, $post);
        $this->createChunk($organization, $dossier, $post, $this->vector(0.0), 'Old model', model: 'old-model');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([], $results);
    }

    public function test_old_embedding_provider_is_excluded(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        $this->fakeQueryEmbedding();
        $post = $this->createBlogPost($organization, $owner);
        $this->attachPost($organization, $dossier, $owner, $post);
        $this->createChunk($organization, $dossier, $post, $this->vector(0.0), 'Old provider', provider: 'ollama');
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle');

        $this->assertSame([], $results);
    }

    private function assertPostgresqlPgvectorPreconditions(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bouclepro_test', DB::connection()->getDatabaseName());
        $this->assertSame('0.8.5', DB::table('pg_extension')->where('extname', 'vector')->value('extversion'));
    }

    /**
     * @return array{0: Organization, 1: Dossier, 2: User}
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = $this->createDossier($organization, $owner);

        return [$organization, $dossier, $owner];
    }

    private function createDossier(Organization $organization, User $owner): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'Semantic dossier '.Str::uuid(),
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function createBlogPost(Organization $organization, User $owner, array $attributes = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Semantic article '.Str::uuid(),
            'slug' => 'semantic-article-'.Str::uuid(),
            'content' => '<p>Semantic article content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    private function attachedChunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content, array $postAttributes = []): DossierChunk
    {
        $post = $this->createBlogPost($organization, $owner, $postAttributes);
        $this->attachPost($organization, $dossier, $owner, $post);

        return $this->createChunk($organization, $dossier, $post, $vector, $content);
    }

    private function attachPost(Organization $organization, Dossier $dossier, User $owner, BlogPost $post): void
    {
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);
    }

    private function createChunk(
        Organization $organization,
        Dossier $dossier,
        BlogPost $post,
        array $vector,
        string $content,
        string $provider = 'openai',
        string $model = 'text-embedding-3-small',
    ): DossierChunk {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'token_count' => 3,
            'embedding' => $vector,
            'embedding_provider' => $provider,
            'embedding_model' => $model,
            'indexed_at' => now(),
        ]);
    }

    /**
     * @return array<int, float>
     */
    private function vector(float $secondDimension): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;
        $vector[1] = $secondDimension;

        return $vector;
    }

    private function fakeQueryEmbedding(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', 1536);

        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            return array_map(fn (): array => $this->vector(0.0), $prompt->inputs);
        })->preventStrayEmbeddings();
    }

    private function assertOneQueryEmbeddingGenerated(): void
    {
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['needle']
            && count($prompt) === 1
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'text-embedding-3-small'
            && $prompt->dimensions === 1536);
    }

    private function enableGate(string $organizationId): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', [$organizationId]);
    }
}
