<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgvectorDossierChunksSchemaTest extends TestCase
{
    public function test_postgresql_vector_storage_distance_constraints_and_indexes(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bouclepro_test', DB::connection()->getDatabaseName());

        $extension = DB::table('pg_extension')->where('extname', 'vector')->first();
        $this->assertSame('0.8.5', $extension?->extversion);

        $columnType = DB::table('pg_attribute as a')
            ->join('pg_class as c', 'c.oid', '=', 'a.attrelid')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('n.nspname', 'public')
            ->where('c.relname', 'dossier_chunks')
            ->where('a.attname', 'embedding')
            ->selectRaw('format_type(a.atttypid, a.atttypmod) as type')
            ->value('type');

        $this->assertSame('vector(1536)', $columnType);

        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = $this->createDossier($organization, $owner);
        $post = $this->createBlogPost($organization, $owner);
        $embedding = array_fill(0, 1536, 0.0);
        $embedding[0] = 1.0;

        $chunk = DossierChunk::create([
            'organization_id' => $organization->id,
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

        $this->assertCount(1536, $chunk->fresh()->embedding);

        $distance = DB::table('dossier_chunks')
            ->where('id', $chunk->id)
            ->selectVectorDistance('embedding', $embedding, as: 'distance')
            ->value('distance');

        $this->assertSame(0.0, (float) $distance);

        $this->assertExpectedIndexesExist();

        $this->expectException(QueryException::class);

        DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => '00000000-0000-0000-0000-000000000000',
            'chunk_index' => 1,
            'content' => 'Invalid foreign key chunk.',
            'content_hash' => hash('sha256', 'Invalid foreign key chunk.'),
            'embedding' => $embedding,
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    public function test_postgresql_enforces_chunk_uniqueness(): void
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

    private function assertExpectedIndexesExist(): void
    {
        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'dossier_chunks')
            ->pluck('indexname')
            ->all();

        $this->assertContains('dossier_chunks_organization_id_index', $indexes);
        $this->assertContains('dossier_chunks_dossier_id_index', $indexes);
        $this->assertContains('dossier_chunks_blog_post_id_index', $indexes);
        $this->assertContains('dossier_chunks_organization_id_dossier_id_index', $indexes);
        $this->assertContains('dossier_chunks_unique_chunk_identity', $indexes);
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
        $embedding = array_fill(0, 1536, 0.0);
        $embedding[0] = 1.0;

        return [
            'organization_id' => $organization->id,
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
        ];
    }
}
