<?php

namespace Tests\Feature\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class PgvectorDossierSemanticSearchEndpointTest extends TestCase
{
    public function test_endpoint_returns_top_five_pgvector_results_with_citation_urls(): void
    {
        $this->assertPostgresqlPgvectorPreconditions();
        [$organization, $owner, $dossier] = $this->fixture();
        $this->fakeQueryEmbedding();

        $expectedSlugs = [];

        for ($index = 0; $index < 7; $index++) {
            $chunk = $this->attachedChunk($organization, $dossier, $owner, $this->vector($index / 10), "Chunk {$index}");

            if ($index < 5) {
                $expectedSlugs[] = $chunk->blogPost->slug;
            }
        }

        $this->enableGate($organization->id);

        $response = $this->actingAs($owner)
            ->getJson(route('organization.dossiers.semantic-search', [
                'organization' => $organization,
                'dossier' => $dossier,
                'query' => 'needle',
            ]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.content', 'Chunk 0')
            ->assertJsonPath('data.0.citation_url', route('organization.blog.show', [
                'organization' => $organization,
                'post' => $expectedSlugs[0],
            ]));

        $this->assertSame($expectedSlugs, array_column($response->json('data'), 'slug'));
        $this->assertOneQueryEmbeddingGenerated();
    }

    private function assertPostgresqlPgvectorPreconditions(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bouclepro_test', DB::connection()->getDatabaseName());
        $this->assertSame('0.8.5', DB::table('pg_extension')->where('extname', 'vector')->value('extversion'));
    }

    /**
     * @return array{0: Organization, 1: User, 2: Dossier}
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'Endpoint dossier '.Str::uuid(),
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return [$organization, $owner, $dossier];
    }

    private function attachedChunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content): DossierChunk
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Endpoint article '.Str::uuid(),
            'slug' => 'endpoint-article-'.Str::uuid(),
            'content' => '<p>Endpoint article content</p>',
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

        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
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
