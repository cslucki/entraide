<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\ArticleChunker;
use App\Services\Dossiers\DossierFileIndexer;
use App\Services\Dossiers\FileContentExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1216 — ingestion RAG native des fichiers TXT/Markdown d'un Dossier,
 * meme contrat P4/staleness que l'Article (TASK-1214). DossierFileIndexer
 * est un clone structurel de DossierArticleIndexer : ces tests suivent le
 * meme plan que TASK1214IngestionTenantEmbeddingTest, adapte au fichier.
 */
class TASK1216FileIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.driver', 'openai');
        config()->set('ai.providers.openai.key', 'platform-should-not-be-used');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', $this->dimensions());
    }

    private function dimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }

    // ---- TXT ----

    public function test_txt_ingestion_uses_the_organization_instance_not_the_platform(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(content: 'The Orion Station has exactly 23 violet panels.');
        $this->tenantSetting($org, 'openai', 'sk-tenant-A');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertGreaterThan(0, $count);
        $this->assertSame(['org:'.$org->id.':openai'], array_values(array_unique($seen)));
        $chunk = DossierChunk::query()->where('dossier_file_id', $file->id)->firstOrFail();
        $this->assertNull($chunk->blog_post_id);
        $this->assertSame('openai', $chunk->embedding_provider);
        $this->assertStringContainsString('23 violet panels', $chunk->content);
    }

    // ---- Markdown ----

    public function test_markdown_ingestion_strips_syntax_and_indexes(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(
            content: "# Station Lyra\n\nThe Lyra Station contains **exactly 18** green modules.",
            filename: 'lyra.md',
            mime: 'text/markdown',
        );
        $this->tenantSetting($org, 'openai', 'sk-tenant-md');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertGreaterThan(0, $count);
        $chunk = DossierChunk::query()->where('dossier_file_id', $file->id)->firstOrFail();
        $this->assertStringContainsString('18 green modules', $chunk->content);
        // La syntaxe Markdown ne doit jamais survivre dans le chunk indexe.
        $this->assertStringNotContainsString('#', $chunk->content);
        $this->assertStringNotContainsString('**', $chunk->content);
    }

    // ---- P4 ----

    public function test_a_file_without_p4_produces_no_embedding_and_no_index(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture();
        Embeddings::fake(fn (): array => throw new RuntimeException('Platform embedding must not be called.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_unchanged_source_keeps_historical_index_when_p4_is_absent(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(content: 'stable content');
        $this->storeHistoricalChunk($org, $dossier, $file, 'stable content');
        Embeddings::fake(fn (): array => throw new RuntimeException('No embedding expected.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertGreaterThan(0, $count);
        $this->assertSame(1, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_modified_source_without_p4_removes_the_stale_index(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(content: 'new content that differs');
        $this->storeHistoricalChunk($org, $dossier, $file, 'old content');
        Embeddings::fake(fn (): array => throw new RuntimeException('No embedding expected.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_a_family_mismatch_between_tenant_provider_and_index_is_not_indexed(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture();
        config()->set('ai.providers.openrouter.driver', 'openrouter');
        config()->set('ai.providers.openrouter.key', 'platform-openrouter');
        $this->tenantSetting($org, 'openrouter', 'sk-tenant-openrouter');
        Embeddings::fake(fn (): array => throw new RuntimeException('Mismatched family must not embed.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        Embeddings::assertNothingGenerated();
    }

    public function test_reindexation_succeeds_once_p4_is_restored(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(content: 'content to index');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $this->assertSame(0, app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id));

        $this->tenantSetting($org, 'openai', 'sk-tenant-restored');
        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertGreaterThan(0, $count);
        $this->assertContains('org:'.$org->id.':openai', $seen);
    }

    public function test_no_secret_is_written_into_the_chunks(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture();
        $this->tenantSetting($org, 'openai', 'sk-super-secret-value');
        $seen = [];
        $this->fakeEmbeddings($seen);

        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        foreach (DossierChunk::query()->where('dossier_file_id', $file->id)->get() as $chunk) {
            $this->assertStringNotContainsString('sk-super-secret-value', json_encode($chunk->toArray()));
        }
    }

    // ---- Lifecycle ----

    public function test_replace_content_via_markdown_update_reindexes_without_serving_old_version(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(
            content: 'The Orion Station has exactly 23 violet panels.',
            filename: 'orion.md',
            mime: 'text/markdown',
        );
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        $seen = [];
        $this->fakeEmbeddings($seen);

        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $this->assertStringContainsString('23', DossierChunk::query()->where('dossier_file_id', $file->id)->firstOrFail()->content);

        Storage::disk('dossier_files')->put($file->path, 'The Orion Station has exactly 29 violet panels.');
        $file->update(['checksum_sha256' => hash('sha256', 'The Orion Station has exactly 29 violet panels.')]);

        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $contents = DossierChunk::query()->where('dossier_file_id', $file->id)->get()->pluck('content')->implode(' ');

        $this->assertStringContainsString('29', $contents);
        $this->assertStringNotContainsString('23 violet', $contents);
    }

    public function test_delete_removes_chunks_without_embeddings(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture();
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        $seen = [];
        $this->fakeEmbeddings($seen);
        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $this->assertGreaterThan(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());

        $file->delete();
        $seen = [];
        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        $this->assertSame([], $seen);
    }

    public function test_moving_to_another_dossier_removes_chunks_from_the_original_dossier(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture();
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        $seen = [];
        $this->fakeEmbeddings($seen);
        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $this->assertSame(1, DossierChunk::query()->where('dossier_id', $dossier->id)->where('dossier_file_id', $file->id)->count());

        $owner = User::query()->where('organization_id', $org->id)->firstOrFail();
        $target = Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'name' => 'Target dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        // Simule DossierFileController::move() : le fichier change de Dossier ;
        // le synchronize() sur l'ANCIEN dossier_id doit desormais retirer les
        // chunks (le fichier n'y est plus), celui sur le NOUVEAU les recree.
        $file->update(['dossier_id' => $target->id]);

        $countOld = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $countNew = app(DossierFileIndexer::class)->synchronize($org->id, $target->id, $file->id);

        $this->assertSame(0, $countOld);
        $this->assertGreaterThan(0, $countNew);
        $this->assertSame(0, DossierChunk::query()->where('dossier_id', $dossier->id)->where('dossier_file_id', $file->id)->count());
        $this->assertSame(1, DossierChunk::query()->where('dossier_id', $target->id)->where('dossier_file_id', $file->id)->count());
    }

    public function test_unsupported_mime_type_produces_no_partial_chunk(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture(filename: 'photo.png', mime: 'image/png', content: "\x89PNG\x0d\x0a\x1a\x0a\x00\x00\x00binarydata");
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        Embeddings::fake(fn (): array => throw new RuntimeException('Unsupported file must not embed.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_reexecution_is_idempotent_without_duplicates(): void
    {
        [$org, $dossier, $file] = $this->eligibleFixture();
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        $seen = [];
        $this->fakeEmbeddings($seen);

        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $countAfterFirst = DossierChunk::query()->where('dossier_file_id', $file->id)->count();
        $seen = [];
        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $countAfterSecond = DossierChunk::query()->where('dossier_file_id', $file->id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertSame([], $seen); // deuxieme appel : alreadyIndexed, pas de nouvel embed
    }

    // ---- Tenant ----

    public function test_cross_tenant_file_is_not_indexed_for_another_organization(): void
    {
        [$orgA, $dossierA, $fileA] = $this->eligibleFixture();
        $orgB = Organization::factory()->create();
        $this->tenantSetting($orgA, 'openai', 'sk-tenant-a');

        $count = app(DossierFileIndexer::class)->synchronize($orgB->id, $dossierA->id, $fileA->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $fileA->id)->count());
    }

    // ---- helpers ----

    /** @return array{0: Organization, 1: Dossier, 2: DossierFile} */
    private function eligibleFixture(
        string $content = 'searchable file content, long enough to produce a chunk',
        string $filename = 'notes.txt',
        string $mime = 'text/plain',
    ): array {
        $org = Organization::factory()->create();
        config()->set(
            'ai.dossiers.semantic_search.organization_ids',
            array_unique(array_merge(config('ai.dossiers.semantic_search.organization_ids', []), [$org->id])),
        );
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $dossier = Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1216 folder '.$org->id,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $path = 'dossier-files/'.$dossier->id.'/'.$filename;
        Storage::disk('dossier_files')->put($path, $content);

        $file = DossierFile::create([
            'organization_id' => $org->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => $filename,
            'display_name' => $filename,
            'mime_type' => $mime,
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'source' => 'upload',
        ]);

        return [$org, $dossier, $file];
    }

    private function tenantSetting(Organization $org, string $provider, string $key): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => $provider,
            'model' => 'gpt-4o-mini',
            'api_key' => $key,
        ]);
    }

    private function storeHistoricalChunk(Organization $org, Dossier $dossier, DossierFile $file, string $rawText): void
    {
        $text = app(FileContentExtractor::class)->extract($rawText, $file->mime_type, $file->original_name);
        $chunks = app(ArticleChunker::class)->chunk((string) $text);
        foreach ($chunks as $chunk) {
            DossierChunk::create([
                'organization_id' => $org->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => null,
                'dossier_file_id' => $file->id,
                'chunk_index' => $chunk['chunk_index'],
                'content' => $chunk['content'],
                'content_hash' => $chunk['content_hash'],
                'token_count' => $chunk['token_count'],
                'embedding' => array_fill(0, $this->dimensions(), 0.1),
                'embedding_provider' => 'openai',
                'embedding_model' => 'text-embedding-3-small',
                'indexed_at' => now()->subDay(),
            ]);
        }
    }

    /** @param array<int,string> $seen */
    private function fakeEmbeddings(array &$seen): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen): array {
            $seen[] = $prompt->provider->name();

            return array_map(fn (int $i): array => array_fill(0, $prompt->dimensions, ($i + 1) / 10), array_keys($prompt->inputs));
        })->preventStrayEmbeddings();
    }
}
