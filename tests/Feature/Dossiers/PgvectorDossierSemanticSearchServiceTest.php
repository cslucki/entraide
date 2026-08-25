<?php

namespace Tests\Feature\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\DossierFile;
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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

        $this->assertSame($near->blog_post_id, $results[0]['blog_post_id']);
        $this->assertSame($far->blog_post_id, $results[1]['blog_post_id']);
        $this->assertSame(['source_type', 'blog_post_id', 'title', 'slug', 'dossier_file_id', 'filename', 'mime_type', 'chunk_index', 'content', 'distance'], array_keys($results[0]));
        $this->assertNull($results[0]['mime_type']);
        $this->assertSame('article', $results[0]['source_type']);
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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai', 5);

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

        $this->assertSame([$expected->blog_post_id], array_column($results, 'blog_post_id'));
    }

    /**
     * TASK-1267 : un chunk de FICHIER d'une autre Organization ne sort jamais,
     * ni par sa propre ligne (organization_id etranger), ni via un chunk qui
     * porterait nos identifiants mais pointerait vers un dossier_file
     * etranger (la jointure dossier_files exige le meme dossier_id et
     * `dossier_files.organization_id` = tenant). Le fichier du tenant, lui,
     * sort avec la forme `file` (title/slug nuls, filename renseigne).
     */
    public function test_file_chunk_of_another_organization_is_never_returned(): void
    {
        // TASK-1267 : saute — et ne desactive pas — ce test sur un driver qui ne
        // peut structurellement pas l'executer (SQLite sans pgvector), selon la
        // convention de PgvectorDossierRetrievalSourceTest::setUp(). Les 10 tests
        // historiques de la classe restent inscrits tels quels dans
        // .github/sqlite-known-failures.txt ; cette reference ne grandit pas.
        // Sous PostgreSQL le test tourne et prouve l'isolation tenant.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('dossier semantic search tenant isolation requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }

        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $dossier, $owner] = $this->fixture();
        [$otherOrganization, $otherDossier, $otherOwner] = $this->fixture();
        $this->fakeQueryEmbedding();

        $ownFile = $this->fileChunk($organization, $dossier, $owner, $this->vector(0.3), 'Own file passage', 'contrat-2026.txt');
        $foreignFile = $this->fileChunk($otherOrganization, $otherDossier, $otherOwner, $this->vector(0.0), 'Foreign file passage', 'secret.txt');
        // Chunk forge : nos identifiants, mais dossier_file_id d'un fichier etranger.
        DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => null,
            'dossier_file_id' => $foreignFile->dossier_file_id,
            'chunk_index' => 7,
            'content' => 'Forged pointer to foreign file',
            'content_hash' => hash('sha256', 'forged'),
            'token_count' => 3,
            'embedding' => $this->vector(0.0),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);
        $this->enableGate($organization->id);

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

        $this->assertSame([$ownFile->dossier_file_id], array_column($results, 'dossier_file_id'));
        $this->assertSame('file', $results[0]['source_type']);
        $this->assertSame('contrat-2026.txt', $results[0]['filename']);
        // TASK-1267 : le MIME du fichier est expose (apercu in-app cote vue).
        $this->assertSame('text/plain', $results[0]['mime_type']);
        $this->assertNull($results[0]['blog_post_id']);
        $this->assertNull($results[0]['title']);
        $this->assertNull($results[0]['slug']);
        $this->assertStringNotContainsString('secret.txt', json_encode($results));
        $this->assertStringNotContainsString('Foreign', json_encode($results));
        $this->assertStringNotContainsString('Forged', json_encode($results));
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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

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

        $results = app(DossierSemanticSearchService::class)->search($organization->id, $dossier->id, 'needle', 'openai');

        $this->assertSame([], $results);
    }

    private function assertPostgresqlPgvectorPreconditions(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bouclepro_test', DB::connection()->getDatabaseName());
        // **L'extension doit etre la ; sa version de correctif n'est pas une
        // regle du produit.** Epingler `0.8.5` faisait echouer ces tests sur
        // toute autre installation — c'est ce qu'a montre le premier passage
        // reel de la CI, dont l'image pgvector porte une autre version. Un test
        // qui asserte son environnement casse des qu'il change d'environnement.
        $this->assertNotNull(
            DB::table('pg_extension')->where('extname', 'vector')->value('extversion'),
            'l’extension pgvector est absente',
        );
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

    /**
     * Chunk indexe depuis un fichier du Dossier (source_type = 'file').
     */
    private function fileChunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content, string $filename): DossierChunk
    {
        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.Str::uuid().'.txt',
            'original_name' => $filename,
            'display_name' => $filename,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'source' => 'upload',
        ]);

        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'token_count' => 3,
            'embedding' => $vector,
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
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
