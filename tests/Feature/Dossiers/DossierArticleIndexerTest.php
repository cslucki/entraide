<?php

namespace Tests\Feature\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\ArticleChunker;
use App\Services\Dossiers\ArticleTextExtractor;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\Dossiers\DossierChunkEmbeddingService;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Support\Tenancy\CurrentOrganization;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use RuntimeException;
use Tests\TestCase;

class DossierArticleIndexerTest extends TestCase
{
    public function test_published_attached_article_is_indexed(): void
    {
        [$organization, $owner, $dossier, $post] = $this->eligibleFixture(content: $this->words(620));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(2, $count);
        $this->assertSame(2, DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count());
        $this->assertFalse(app()->bound('current_organization'));
        $this->assertNull(CurrentOrganization::get());
        $this->assertSame($owner->organization_id, $organization->id);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => count($prompt) === 2);
    }

    public function test_current_organization_is_bound_during_indexing_and_cleared_after_success(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();
        $probe = new class
        {
            public ?string $seenOrganizationId = null;
        };

        $indexer = new DossierArticleIndexer(
            app(DossierSemanticSearchGate::class),
            new class($probe) extends ArticleTextExtractor
            {
                public function __construct(private object $probe) {}

                public function extract(string $html): string
                {
                    $this->probe->seenOrganizationId = CurrentOrganization::id();

                    return parent::extract($html);
                }
            },
            app(ArticleChunker::class),
            app(DossierChunkEmbeddingService::class),
        );

        $this->assertFalse(app()->bound('current_organization'));

        $indexer->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame($organization->id, $probe->seenOrganizationId);
        $this->assertFalse(app()->bound('current_organization'));
        $this->assertNull(CurrentOrganization::get());
    }

    public function test_previous_current_organization_is_restored_after_success(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $previousOrganization = Organization::factory()->create();
        app()->instance('current_organization', $previousOrganization);
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertSame($previousOrganization->id, CurrentOrganization::id());
    }

    public function test_embedding_metadata_is_stored(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $chunk = DossierChunk::query()->firstOrFail();
        $this->assertSame('openai', $chunk->embedding_provider);
        $this->assertSame('text-embedding-3-small', $chunk->embedding_model);
        $this->assertCount(8, $chunk->embedding);
        $this->assertNotNull($chunk->indexed_at);
    }

    public function test_chunk_index_order_is_preserved(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(content: $this->words(620));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame([0, 1], DossierChunk::query()->orderBy('chunk_index')->pluck('chunk_index')->all());
    }

    public function test_reexecution_is_idempotent_without_duplicates(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(content: $this->words(620));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $first = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);
        $second = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(2, $first);
        $this->assertSame(2, $second);
        $this->assertSame(2, DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count());
    }

    public function test_modified_content_replaces_old_chunks(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(content: $this->words(620, 'old'));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $post->update(['content' => '<p>new compact content</p>']);

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(1, $count);
        $this->assertSame(1, DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count());
        $this->assertSame('new compact content', DossierChunk::query()->firstOrFail()->content);
    }

    public function test_draft_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(['status' => 'draft']);

        $this->assertIneligibleDeletesExistingChunks($organization, $dossier, $post);
    }

    public function test_future_publication_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(['published_at' => now()->addDay()]);

        $this->assertIneligibleDeletesExistingChunks($organization, $dossier, $post);
    }

    public function test_detached_article_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        DossierBlogPost::query()->where('blog_post_id', $post->id)->delete();

        $this->assertIneligibleDeletesExistingChunks($organization, $dossier, $post);
    }

    public function test_soft_deleted_article_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $post->delete();

        $this->assertIneligibleDeletesExistingChunks($organization, $dossier, $post);
    }

    public function test_empty_content_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(content: '<script>hidden()</script>');

        $this->assertIneligibleDeletesExistingChunks($organization, $dossier, $post);
    }

    public function test_disabled_gate_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization, enabled: false);
        $this->assertExistingChunk($organization, $dossier, $post);
        Embeddings::fake(fn (): array => throw new RuntimeException('Embeddings should not be called.'))->preventStrayEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_organization_outside_allowlist_removes_chunks_without_embeddings(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $this->enableGate(Organization::factory()->create());
        $this->assertExistingChunk($organization, $dossier, $post);
        Embeddings::fake(fn (): array => throw new RuntimeException('Embeddings should not be called.'))->preventStrayEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_cross_tenant_article_is_not_indexed(): void
    {
        [$organization, , $dossier] = $this->eligibleFixture();
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherPost = $this->createBlogPost($otherOrganization, $otherOwner);
        $this->enableGate($organization);
        Embeddings::fake(fn (): array => throw new RuntimeException('Embeddings should not be called.'))->preventStrayEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $otherPost->id);

        $this->assertSame(0, $count);
        Embeddings::assertNothingGenerated();
    }

    public function test_cross_tenant_ineligibility_does_not_delete_other_organization_chunks(): void
    {
        [$organization, , $dossier] = $this->eligibleFixture();
        $otherOrganization = Organization::factory()->create();
        $otherOwner = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherDossier = $this->createDossier($otherOrganization, $otherOwner);
        $otherPost = $this->createBlogPost($otherOrganization, $otherOwner);
        $this->assertExistingChunk($otherOrganization, $otherDossier, $otherPost);
        $this->enableGate($organization);
        Embeddings::fake(fn (): array => throw new RuntimeException('Embeddings should not be called.'))->preventStrayEmbeddings();

        $this->indexer()->synchronize($organization->id, $dossier->id, $otherPost->id);

        $this->assertSame(1, DossierChunk::query()->where('organization_id', $otherOrganization->id)->count());
    }

    public function test_provider_failure_preserves_existing_chunks(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $previousOrganization = Organization::factory()->create();
        app()->instance('current_organization', $previousOrganization);
        $this->enableGate($organization);
        $this->assertExistingChunk($organization, $dossier, $post);
        Embeddings::fake(fn (): array => throw new RuntimeException('Provider unavailable.'))->preventStrayEmbeddings();

        try {
            $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);
            $this->fail('Expected provider exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider unavailable.', $exception->getMessage());
        }

        $this->assertSame('old chunk', DossierChunk::query()->firstOrFail()->content);
        $this->assertTrue(app()->bound('current_organization'));
        $this->assertSame($previousOrganization->id, CurrentOrganization::id());
    }

    public function test_invalid_embedding_response_preserves_existing_chunks(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture();
        $this->enableGate($organization);
        $this->assertExistingChunk($organization, $dossier, $post);
        Embeddings::fake(fn (): array => [[0.1, 0.2]])->preventStrayEmbeddings();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding vector at index 0 does not match configured dimensions.');

        try {
            $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);
        } finally {
            $this->assertSame('old chunk', DossierChunk::query()->firstOrFail()->content);
        }
    }

    public function test_returned_count_matches_stored_chunks(): void
    {
        [$organization, , $dossier, $post] = $this->eligibleFixture(content: $this->words(620));
        $this->enableGate($organization);
        $this->fakeEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count(), $count);
    }

    private function assertIneligibleDeletesExistingChunks(Organization $organization, Dossier $dossier, BlogPost $post): void
    {
        $this->enableGate($organization);
        $this->assertExistingChunk($organization, $dossier, $post);
        Embeddings::fake(fn (): array => throw new RuntimeException('Embeddings should not be called.'))->preventStrayEmbeddings();

        $count = $this->indexer()->synchronize($organization->id, $dossier->id, $post->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_id', $dossier->id)->where('blog_post_id', $post->id)->count());
        Embeddings::assertNothingGenerated();
    }

    private function assertExistingChunk(Organization $organization, Dossier $dossier, BlogPost $post): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => 'old chunk',
            'content_hash' => hash('sha256', 'old chunk'),
            'token_count' => 2,
            'embedding' => array_fill(0, 8, 0.9),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now()->subMinute(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $postAttributes
     * @return array{0: Organization, 1: User, 2: Dossier, 3: BlogPost}
     */
    private function eligibleFixture(array $postAttributes = [], string $content = '<p>searchable article content</p>'): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = $this->createDossier($organization, $owner);
        $post = $this->createBlogPost($organization, $owner, array_merge(['content' => $content], $postAttributes));

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return [$organization, $owner, $dossier, $post];
    }

    private function createDossier(Organization $organization, User $owner): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'Semantic folder',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createBlogPost(Organization $organization, User $author, array $attributes = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'organization_id' => $organization->id,
            'user_id' => $author->id,
            'title' => 'Semantic article',
            'slug' => 'semantic-article-'.$organization->id,
            'content' => '<p>searchable article content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    private function enableGate(Organization $organization, bool $enabled = true): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', $enabled);
        config()->set('ai.dossiers.semantic_search.organization_ids', [$organization->id]);
    }

    private function fakeEmbeddings(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', 8);

        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            return array_map(
                fn (int $index): array => array_fill(0, $prompt->dimensions, ($index + 1) / 10),
                array_keys($prompt->inputs),
            );
        })->preventStrayEmbeddings();
    }

    private function indexer(): DossierArticleIndexer
    {
        return app(DossierArticleIndexer::class);
    }

    private function words(int $count, string $prefix = 'word'): string
    {
        return '<p>'.implode(' ', array_map(fn (int $index): string => $prefix.$index, range(1, $count))).'</p>';
    }
}
