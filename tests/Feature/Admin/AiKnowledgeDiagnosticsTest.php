<?php

namespace Tests\Feature\Admin;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

/**
 * TASK-1307 — console de connaissances : diagnostic (« Inspecter » les
 * chunks reellement indexes d'une source, « Tester la recherche »
 * documentaire brute sans generation LLM).
 *
 * Doctrine : lire la page ne coute rien (zero appel provider) ; inspecter
 * une source ne coute rien (lecture locale) ; seule une recherche EXPLICITE
 * (query non vide) coute UN embedding, via le meme pipeline et la meme
 * garde economique que le retrieval servi aux membres — rien n'est
 * contourne, rien n'est duplique.
 */
class AiKnowledgeDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Inspecter — chunks reellement indexes
    // =====================================================================

    public function test_inspecting_an_indexed_article_shows_its_stored_chunks(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier');
        $post = $this->article($organization, $dossier, $admin, 'Cadre du dialogue');
        $this->chunk($organization, $dossier, $post, 0, 'Premier extrait vraiment stocke.');
        $this->chunk($organization, $dossier, $post, 1, 'Second extrait vraiment stocke.');

        $response = $this->actingAs($admin)->get($this->chunksUrl($organization, 'article', $post->id));

        $response->assertOk();
        $response->assertSee('Cadre du dialogue');
        $response->assertSee('Premier extrait vraiment stocke.');
        $response->assertSee('Second extrait vraiment stocke.');
        // Jamais le vecteur embedding : ni sa forme JSON, ni ses valeurs.
        $response->assertDontSee('0.10000');
    }

    public function test_inspecting_an_unindexed_source_says_so_without_inventing_chunks(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier');
        $post = $this->article($organization, $dossier, $admin, 'Jamais indexe');

        $response = $this->actingAs($admin)->get($this->chunksUrl($organization, 'article', $post->id));

        $response->assertOk();
        $response->assertSee('Jamais indexe');
        $response->assertDontSee('Extrait 0');
    }

    public function test_inspecting_a_source_of_another_organization_is_refused(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        [$otherOrganization, $otherAdmin] = $this->organizationWithAdmin();
        $otherDossier = $this->dossier($otherOrganization, $otherAdmin, 'Dossier étranger');
        $otherPost = $this->article($otherOrganization, $otherDossier, $otherAdmin, 'SECRET-AUTRE-ORG');
        $this->chunk($otherOrganization, $otherDossier, $otherPost, 0, 'Contenu confidentiel autre organization.');

        $response = $this->actingAs($admin)->get($this->chunksUrl($organization, 'article', $otherPost->id));

        $response->assertNotFound();
    }

    public function test_inspecting_requires_org_admin(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = $this->dossier($organization, $admin, 'Dossier');
        $post = $this->article($organization, $dossier, $admin, 'Article');

        $this->actingAs($member)->get($this->chunksUrl($organization, 'article', $post->id))->assertForbidden();
    }

    // =====================================================================
    // Cout — lire la page / inspecter ne coute jamais rien
    // =====================================================================

    public function test_opening_the_page_and_inspecting_a_source_never_calls_a_provider(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $dossier = $this->dossier($organization, $admin, 'Dossier');
        $post = $this->article($organization, $dossier, $admin, 'Article');
        $this->chunk($organization, $dossier, $post, 0, 'Contenu.');

        $this->actingAs($admin)->get(route('organization.admin.ai-knowledge', ['organization' => $organization->slug]))->assertOk();
        $this->actingAs($admin)->get($this->chunksUrl($organization, 'article', $post->id))->assertOk();
        // Une recherche SANS requete ne coute rien non plus.
        $this->actingAs($admin)->get(route('organization.admin.ai-knowledge.search', ['organization' => $organization->slug]))->assertOk();

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // Tester la recherche — pgvector seul, jamais de generation
    // =====================================================================

    public function test_raw_search_returns_ranked_results_without_any_generation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('recherche documentaire brute : requiert pgvector.');
        }

        [$organization, $admin] = $this->organizationWithAdmin();
        $this->credential($organization);
        $loop = $this->loop($organization, $admin, 'Boucle diagnostic');
        $dossier = $this->loopDossier($organization, $loop, 'Dossier de la Boucle');
        $post = $this->article($organization, $dossier, $admin, 'Manifeste');
        $this->chunk($organization, $dossier, $post, 0, 'Une Boucle est un contexte de progression humaine.');

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $response = $this->actingAs($admin)->get(route('organization.admin.ai-knowledge.search', [
            'organization' => $organization->slug,
            'q' => 'contexte de progression humaine',
        ]));

        $response->assertOk();
        $response->assertSee('Manifeste');
        $response->assertSee('progression humaine', false);

        // Un SEUL embedding de requete — jamais de generation : aucune
        // ligne de type generation n'existe, et aucun agent LLM n'a ete
        // faussement configure ici pour repondre — s'il avait ete appele,
        // aucun fake ne l'aurait absorbe et le test aurait leve une erreur
        // de transport reel (Http::preventStrayRequests()).
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['contexte de progression humaine']);
    }

    public function test_raw_search_stays_within_the_organization(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('recherche documentaire brute : requiert pgvector.');
        }

        [$organization, $admin] = $this->organizationWithAdmin();
        $this->credential($organization);
        $dossier = $this->dossier($organization, $admin, 'Dossier');
        $post = $this->article($organization, $dossier, $admin, 'Chez nous');
        $this->chunk($organization, $dossier, $post, 0, 'Contenu chez nous.', 0.0);

        [$otherOrganization, $otherAdmin] = $this->organizationWithAdmin();
        $this->credential($otherOrganization);
        $otherDossier = $this->dossier($otherOrganization, $otherAdmin, 'Dossier étranger');
        $otherPost = $this->article($otherOrganization, $otherDossier, $otherAdmin, 'SECRET-AUTRE-ORG');
        $this->chunk($otherOrganization, $otherDossier, $otherPost, 0, 'Contenu confidentiel autre organization.', 0.0);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $response = $this->actingAs($admin)->get(route('organization.admin.ai-knowledge.search', [
            'organization' => $organization->slug,
            'q' => 'contenu',
        ]));

        $response->assertOk();
        $response->assertSee('Chez nous');
        $response->assertDontSee('SECRET-AUTRE-ORG');
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $admin->id]);

        return [$organization->fresh(), $admin];
    }

    private function credential(Organization $organization): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-diagnostics-'.$organization->id,
        ]);
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
        ]);
        config(['ai.dossiers.semantic_search.organization_ids' => array_unique(array_merge(
            (array) config('ai.dossiers.semantic_search.organization_ids', []),
            [(string) $organization->id],
        ))]);
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
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function dossier(Organization $organization, User $owner, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function article(Organization $organization, Dossier $dossier, User $author, string $title): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::uuid(),
            'content' => '<p>'.$title.'</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
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

    private function chunk(Organization $organization, Dossier $dossier, BlogPost $post, int $chunkIndex, string $content, float $second = 0.1): DossierChunk
    {
        $dimensions = config('database.default') === 'pgsql' ? 1536 : 8;

        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'content_hash' => hash('sha256', $content.$chunkIndex),
            'token_count' => 5,
            'embedding' => array_pad([1.0, $second], $dimensions, 0.0),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function chunksUrl(Organization $organization, string $type, string $sourceId): string
    {
        return route('organization.admin.ai-knowledge.source', [
            'organization' => $organization->slug,
            'type' => $type,
            'source' => $sourceId,
        ]);
    }

    /**
     * @return array<int, float>
     */
    private function vector(float $second): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;
        $vector[1] = $second;

        return $vector;
    }
}
