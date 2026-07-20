<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DossierChunksSchemaTest extends TestCase
{
    public function test_sqlite_schema_model_relations_and_tenant_scope_are_supported_without_vector_distance(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = $this->createDossier($organization, $owner);
        $post = $this->createBlogPost($organization, $owner);

        app()->instance('current_organization', $organization);

        $embedding = $this->embeddingVector();

        $chunk = DossierChunk::create([
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => 'Article chunk content.',
            'content_hash' => hash('sha256', 'Article chunk content.'),
            'token_count' => 3,
            'embedding' => $embedding,
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $this->assertTrue(Schema::hasTable('dossier_chunks'));
        $this->assertTrue(Schema::hasColumn('dossier_chunks', 'embedding'));
        $this->assertIsString($chunk->id);
        $this->assertSame($organization->id, $chunk->organization_id);
        $this->assertSame($dossier->id, $chunk->dossier->id);
        $this->assertSame($post->id, $chunk->blogPost->id);
        $this->assertSame($organization->id, $chunk->organization->id);
        $this->assertSame($embedding, $chunk->embedding);
    }

    public function test_sqlite_enforces_chunk_uniqueness(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = $this->createDossier($organization, $owner);
        $post = $this->createBlogPost($organization, $owner);

        $attributes = $this->chunkAttributes($organization, $dossier, $post);

        DossierChunk::create($attributes);

        $this->expectException(QueryException::class);

        DossierChunk::create($attributes);
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

    private function createBlogPost(Organization $organization, User $author): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $author->id,
            'title' => 'Semantic article',
            'content' => 'Semantic article content.',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function chunkAttributes(Organization $organization, Dossier $dossier, BlogPost $post): array
    {
        return [
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => 'Article chunk content.',
            'content_hash' => hash('sha256', 'Article chunk content.'),
            'token_count' => 3,
            'embedding' => $this->embeddingVector(),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ];
    }

    /**
     * @return list<float>
     */
    private function embeddingVector(): array
    {
        $dimensions = config('database.default') === 'pgsql'
            ? (int) config('ai.providers.openai.models.embeddings.dimensions', 1536)
            : 3;

        return array_fill(0, $dimensions, 0.1);
    }
}
