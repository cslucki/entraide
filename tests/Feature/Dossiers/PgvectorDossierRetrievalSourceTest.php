<?php

namespace Tests\Feature\Dossiers;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\LoopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

/**
 * TASK-1213 — la source `dossier.retrieval` sur le VRAI moteur pgvector :
 * perimetre intrinseque a la requete (Organization, Dossiers autorises), un
 * seul embedding de requete sur l'instance SDK du tenant, top-k et ordre.
 *
 * PostgreSQL uniquement : sous SQLite le test est ignore (pas d'entree
 * supplementaire dans la reference des echecs connus).
 */
class PgvectorDossierRetrievalSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('dossier.retrieval integration requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }
    }

    public function test_only_accessible_dossiers_of_the_tenant_are_searched_with_the_tenant_embedding_instance(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $otherMember = User::factory()->create(['organization_id' => $organization->id]);
        $stranger = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $loop = (new LoopService)->createLoop($member, 'Boucle pgvector');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'api_key' => 'sk-tenant']);
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 1.0,
        ]);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $visible = $this->dossier($organization, $otherMember, Dossier::VISIBILITY_ORGANIZATION, 'Visible');
        $private = $this->dossier($organization, $otherMember, Dossier::VISIBILITY_PRIVATE, 'Privé');
        $foreign = $this->dossier($otherOrganization, $stranger, Dossier::VISIBILITY_ORGANIZATION, 'Étranger');

        $this->chunk($organization, $visible, $otherMember, $this->vector(0.3), 'Visible far');
        $near = $this->chunk($organization, $visible, $otherMember, $this->vector(0.0), 'Visible near');
        $this->chunk($organization, $private, $otherMember, $this->vector(0.0), 'Private near');
        $this->chunk($otherOrganization, $foreign, $stranger, $this->vector(0.0), 'Foreign near');

        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $member->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'needle',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);

        $this->assertCount(2, $provenance);
        $this->assertSame($near->id, $provenance[0]['chunk_id']);
        $this->assertSame([$visible->id, $visible->id], array_column($provenance, 'dossier_id'));
        $this->assertStringNotContainsString('Private near', $borne->text);
        $this->assertStringNotContainsString('Foreign near', $borne->text);
        $this->assertStringContainsString('[S1] ', $borne->text);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['needle']
            && $prompt->provider->name() === 'org:'.$organization->id.':openrouter'
            && $prompt->model === 'openai/text-embedding-3-small');
    }

    public function test_an_article_ingested_via_the_tenant_instance_is_retrievable_end_to_end(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($owner, 'Boucle ingestion pgvector');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-tenant-e2e',
        ]);
        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openai',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openai.models.embeddings.default' => 'text-embedding-3-small',
            'ai.providers.openai.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 2.0,
        ]);

        $seen = [];
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen): array {
            $seen[] = $prompt->provider->name();

            return array_map(fn (): array => $this->vector(0.0), $prompt->inputs);
        })->preventStrayEmbeddings();

        // Dossier accessible au demandeur (proprietaire), Article publie+attache.
        $dossier = $this->dossier($organization, $owner, Dossier::VISIBILITY_PRIVATE, 'Ingestion e2e');
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Station Quartz',
            'slug' => 'station-quartz-'.Str::uuid(),
            'content' => '<p>La Station Quartz utilise exactement 37 balises orange.</p>',
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

        // Ingestion reelle via l'instance tenant.
        $count = app(DossierArticleIndexer::class)
            ->synchronize($organization->id, $dossier->id, $post->id);
        $this->assertGreaterThan(0, $count);
        $this->assertContains('org:'.$organization->id.':openai', $seen);

        // Retrieval : le contenu ingere est retrouvable, cite avec sa source.
        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $owner->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'Combien de balises utilise la Station Quartz ?',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $this->assertNotEmpty($provenance);
        $this->assertSame('Station Quartz', $provenance[0]['title']);
        $this->assertStringContainsString('37 balises orange', $borne->text);
        // La requete aussi passe par l'instance tenant.
        $this->assertContains('org:'.$organization->id.':openai', $seen);
    }

    private function dossier(Organization $organization, User $owner, string $visibility, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
        ]);
    }

    private function chunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content): DossierChunk
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Article '.$content,
            'slug' => 'article-'.Str::uuid(),
            'content' => '<p>'.$content.'</p>',
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
            'content_hash' => hash('sha256', $content.Str::uuid()),
            'token_count' => 3,
            'embedding' => $vector,
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
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
