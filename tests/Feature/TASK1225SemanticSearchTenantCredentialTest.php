<?php

namespace Tests\Feature;

use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1225 — la recherche semantique d'UN Dossier passe par le credential
 * de l'Organization, jamais par la cle plateforme (fermeture du chemin
 * identifie par la red-team D de TASK-1224).
 *
 * Doctrine P4, identique a l'ingestion et au retrieval : une Organization
 * sans embedding tenant utilisable n'a PAS de recherche semantique — refus
 * explicite (503), aucun appel provider, aucune ligne de ledger. Aucun repli
 * silencieux vers la cle plateforme.
 */
class TASK1225SemanticSearchTenantCredentialTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->member = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'TASK1225 dossier '.Str::uuid(),
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'title' => 'TASK1225 article',
            'slug' => 'task1225-article-'.Str::uuid(),
            'content' => '<p>contenu</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', [$this->organization->id]);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.driver', 'openai');
        config()->set('ai.providers.openai.key', 'platform-key-never-used-for-tenants');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', config('database.default') === 'pgsql' ? 1536 : 8);
    }

    public function test_without_tenant_credential_no_call_leaves_and_the_refusal_is_explicit(): void
    {
        // Aucune configuration IA d'Organization : la cle plateforme existe en
        // config, elle ne doit JAMAIS servir un tenant.
        Embeddings::fake(function (): never {
            throw new RuntimeException('No platform embedding may be emitted for a tenant.');
        })->preventStrayEmbeddings();

        $response = $this->actingAs($this->member)->getJson($this->url('quelle est la question ?'));

        $response->assertStatus(503);
        $response->assertJson(['code' => 'semantic_search_unavailable']);
        Embeddings::assertNothingGenerated();
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_with_tenant_credential_the_query_runs_on_the_tenant_instance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Semantic search requires PostgreSQL pgvector.');
        }

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1225-tenant',
        ]);

        $seenInstances = [];
        $dimensions = (int) config('ai.providers.openai.models.embeddings.dimensions');
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seenInstances, $dimensions): EmbeddingsResponse {
            $seenInstances[] = $prompt->provider->name();
            $vectors = array_map(fn (): array => array_fill(0, $dimensions, 0.1), $prompt->inputs);

            return new EmbeddingsResponse($vectors, 3, new Meta($prompt->provider->name(), $prompt->model));
        })->preventStrayEmbeddings();

        $response = $this->actingAs($this->member)->getJson($this->url('que contient ce dossier ?'));

        $response->assertOk();
        // L'appel est parti sur l'instance TENANT, pas la famille nue.
        $this->assertSame(['org:'.$this->organization->id.':openai'], $seenInstances);

        // Et le ledger canonique le prouve : query embedding, credential
        // ORGANIZATION demontre par le registre du resolver.
        $row = AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->firstOrFail();
        $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $row->embedding_operation);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);
        $this->assertSame((string) $this->organization->id, (string) $row->organization_id);
    }

    public function test_a_platform_family_misconfiguration_is_a_clean_503_not_a_500(): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1225-tenant',
        ]);
        // Famille d'embedding configuree sans providers : DomainException du
        // resolver — un defaut d'exploitation doit rendre le meme 503 propre.
        config()->set('ai.default_for_embeddings', 'family-without-config');

        Embeddings::fake(function (): never {
            throw new RuntimeException('No call expected.');
        })->preventStrayEmbeddings();

        $response = $this->actingAs($this->member)->getJson($this->url('question'));

        $response->assertStatus(503);
        $response->assertJson(['code' => 'semantic_search_unavailable']);
        Embeddings::assertNothingGenerated();
    }

    private function url(string $query): string
    {
        return route('organization.dossiers.semantic-search', [
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
        ]).'?query='.urlencode($query);
    }
}
