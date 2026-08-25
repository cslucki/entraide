<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\ArticleChunker;
use App\Services\Dossiers\ArticleTextExtractor;
use App\Services\Dossiers\DossierArticleIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1214 — l'ingestion des embeddings passe par le credential P4 de
 * l'Organization, jamais par la cle plateforme. Doctrine :
 * - avec P4 : instance tenant utilisee ;
 * - sans P4 : aucun nouvel embedding, aucun repli plateforme ;
 * - source inchangee + P4 absent : index historique conserve ;
 * - source modifiee + P4 absent : ancien contenu retire (jamais servi comme actuel) ;
 * - echec d'embedding apres changement : ancien contenu retire ;
 * - famille d'embedding incoherente : pas d'indexation.
 */
class TASK1214IngestionTenantEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.driver', 'openai');
        config()->set('ai.providers.openai.key', 'platform-should-not-be-used');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', $this->dimensions());
    }

    /**
     * La colonne pgvector est typee vector(1536) : sur PostgreSQL les vecteurs
     * doivent faire exactement cette taille ; sous SQLite, 8 suffit et allege
     * les fixtures (meme regle que DossierArticleIndexerTest).
     */
    private function dimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }

    public function test_ingestion_uses_the_organization_instance_not_the_platform(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture();
        $this->tenantSetting($org, 'openai', 'sk-tenant-A');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $count = app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        $this->assertGreaterThan(0, $count);
        $this->assertSame(['org:'.$org->id.':openai'], array_values(array_unique($seen)));
        $this->assertSame('sk-tenant-A', config('ai.providers.org:'.$org->id.':openai.key'));
        // La famille enregistree reste l'identite d'index, pas l'instance.
        $chunk = DossierChunk::query()->where('blog_post_id', $post->id)->firstOrFail();
        $this->assertSame('openai', $chunk->embedding_provider);
    }

    public function test_an_organization_without_p4_produces_no_embedding_and_no_index(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture();
        // Aucun OrganizationAiSetting. La cle plateforme existe mais ne doit pas servir.
        Embeddings::fake(fn (): array => throw new RuntimeException('Platform embedding must not be called.'))
            ->preventStrayEmbeddings();

        $count = app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('blog_post_id', $post->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_unchanged_source_keeps_historical_index_when_p4_is_absent(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture(content: '<p>stable content</p>');
        // Index historique produit avec la meme famille (openai), sans setting P4.
        $this->storeHistoricalChunk($org, $dossier, $post, '<p>stable content</p>');
        Embeddings::fake(fn (): array => throw new RuntimeException('No embedding expected.'))
            ->preventStrayEmbeddings();

        $count = app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        // Contenu inchange : conserve tel quel, aucun appel provider.
        $this->assertGreaterThan(0, $count);
        $this->assertSame(1, DossierChunk::query()->where('blog_post_id', $post->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_modified_source_without_p4_removes_the_stale_index(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture(content: '<p>new content that differs</p>');
        // Index historique d'un ANCIEN contenu, sans setting P4.
        $this->storeHistoricalChunk($org, $dossier, $post, '<p>old content</p>');
        Embeddings::fake(fn (): array => throw new RuntimeException('No embedding expected.'))
            ->preventStrayEmbeddings();

        $count = app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        // Contenu change + pas de P4 : l'ancien n'est plus servi comme actuel.
        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('blog_post_id', $post->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_a_family_mismatch_between_tenant_provider_and_index_is_not_indexed(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture();
        // Le tenant est configure sur openrouter alors que l'index est openai.
        config()->set('ai.providers.openrouter.driver', 'openrouter');
        config()->set('ai.providers.openrouter.key', 'platform-openrouter');
        $this->tenantSetting($org, 'openrouter', 'sk-tenant-openrouter');
        Embeddings::fake(fn (): array => throw new RuntimeException('Mismatched family must not embed.'))
            ->preventStrayEmbeddings();

        $count = app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        $this->assertSame(0, $count);
        Embeddings::assertNothingGenerated();
    }

    public function test_reindexation_succeeds_once_p4_is_restored(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture(content: '<p>content to index</p>');
        $seen = [];
        $this->fakeEmbeddings($seen);

        // Sans P4 : rien.
        $this->assertSame(0, app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id));
        $this->assertSame(0, DossierChunk::query()->where('blog_post_id', $post->id)->count());

        // P4 configure : la reindexation reussit via l'instance tenant.
        $this->tenantSetting($org, 'openai', 'sk-tenant-restored');
        $count = app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        $this->assertGreaterThan(0, $count);
        $this->assertContains('org:'.$org->id.':openai', $seen);
    }

    public function test_no_secret_is_written_into_the_chunks(): void
    {
        [$org, $dossier, $post] = $this->eligibleFixture();
        $this->tenantSetting($org, 'openai', 'sk-super-secret-value');
        $seen = [];
        $this->fakeEmbeddings($seen);

        app(DossierArticleIndexer::class)->synchronize($org->id, $dossier->id, $post->id);

        foreach (DossierChunk::query()->where('blog_post_id', $post->id)->get() as $chunk) {
            $this->assertStringNotContainsString('sk-super-secret-value', json_encode($chunk->toArray()));
            $this->assertStringNotContainsString('sk-super-secret-value', (string) $chunk->embedding_provider);
        }
    }

    // ---- helpers ----

    /** @return array{0: Organization, 1: Dossier, 2: BlogPost} */
    private function eligibleFixture(string $content = '<p>searchable article content</p>'): array
    {
        $org = Organization::factory()->create();
        config()->set(
            'ai.dossiers.semantic_search.organization_ids',
            array_unique(array_merge(config('ai.dossiers.semantic_search.organization_ids', []), [$org->id])),
        );
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $dossier = Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1214 folder '.$org->id,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $post = BlogPost::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'title' => 'TASK1214 article',
            'slug' => 'task1214-article-'.$org->id,
            'content' => $content,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        DossierBlogPost::create([
            'organization_id' => $org->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return [$org, $dossier, $post];
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

    private function storeHistoricalChunk(Organization $org, Dossier $dossier, BlogPost $post, string $html): void
    {
        // Reproduit ce que l'extracteur+chunker produiraient pour ce HTML, afin
        // que `alreadyIndexed` (hash + famille) reconnaisse le contenu inchange.
        $text = app(ArticleTextExtractor::class)->extract($html);
        $chunks = app(ArticleChunker::class)->chunk($text);
        foreach ($chunks as $chunk) {
            DossierChunk::create([
                'organization_id' => $org->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
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

    /** @param array<int,string> $seen collects the SDK instance name used for each call */
    private function fakeEmbeddings(array &$seen): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen): array {
            $seen[] = $prompt->provider->name();

            return array_map(fn (int $i): array => array_fill(0, $prompt->dimensions, ($i + 1) / 10), array_keys($prompt->inputs));
        })->preventStrayEmbeddings();
    }
}
