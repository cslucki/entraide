<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiInteraction;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1220 — ledger canonique `ai_provider_invocations`.
 *
 * Une ligne = UNE tentative/appel provider economiquement reel. Ces tests
 * prouvent les invariants du ledger sur les chemins PRODUITS reels
 * (clarification, ingestion Dossiers, recherche semantique), pas sur des
 * doubles du ledger :
 *
 *  - 0 != inconnu (tokens NULL, cout NULL+unknown, vrai zero = 0+known) ;
 *  - credential prouve par le ProviderResolver ou honnetement unknown ;
 *  - pas de ligne fictive quand aucun appel provider n'est parti (NO P4) ;
 *  - pas de deduplication par correlation_id (2 appels = 2 lignes) ;
 *  - jamais de credential dans le ledger ;
 *  - la trace P1 historique et l'autorite economique (`ai_interactions`)
 *    restent intactes : le ledger s'ajoute, il ne remplace rien.
 */
class TASK1220AiProviderInvocationLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    private const API_KEY = 'sk-or-task1220-secret-credential';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => self::API_KEY,
        ]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle TASK1220');

        app()->instance('current_organization', $this->organization);

        AiConfig::set('clarification_enabled', true);
        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 2)
            ->update(['prompt_text' => 'Reformule sans inventer.', 'is_active' => true]);

        config([
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key-never-recorded',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Generation — chemin produit reel (clarify_help_request)
    // =====================================================================

    public function test_a_successful_generation_writes_one_canonical_invocation(): void
    {
        $this->fakeClarifier(inputTokens: 120, outputTokens: 80);

        $this->clarify('jai besoin daide pour un projet europeen');

        $this->assertSame(1, AiProviderInvocation::query()->count());
        $row = AiProviderInvocation::query()->firstOrFail();

        $this->assertSame((string) $this->organization->id, (string) $row->organization_id);
        $this->assertSame((string) $this->member->id, (string) $row->user_id);
        $this->assertSame('clarify_help_request', $row->capability);
        $this->assertSame(AiProviderInvocation::OPERATION_GENERATION, $row->operation);
        $this->assertNull($row->embedding_operation);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-4o-mini', $row->model);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertSame(120, $row->input_tokens);
        $this->assertSame(80, $row->output_tokens);
        $this->assertSame(200, $row->total_tokens);
        $this->assertNotNull($row->sdk_invocation_id);
        $this->assertNull($row->provider_invocation_id);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->completed_at);

        // Le cout vient du catalogue : connu, provenance declaree, en USD.
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        $this->assertSame(AiCost::SOURCE_CATALOG_ESTIMATED, $row->cost_source);
        $this->assertNotNull($row->provider_cost);
        $this->assertSame('USD', $row->currency);

        // Credential prouve : la cle vient d'organization_ai_settings via le
        // ProviderResolver, pas d'une inference.
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);

        // Meme operation metier que la trace P1 : correlation partagee.
        $interaction = AiInteraction::query()->firstOrFail();
        $this->assertSame((string) $interaction->correlation_id, (string) $row->correlation_id);
        $this->assertSame($interaction->metadata['sdk_invocation_id'], $row->sdk_invocation_id);
    }

    public function test_a_failed_generation_writes_null_tokens_never_zero(): void
    {
        HelpRequestClarifierAgent::fake(function (): never {
            throw new RuntimeException('provider unreachable');
        });

        // L'echec provider retombe sur la clarification deterministe : le
        // membre n'est pas bloque, mais la tentative reelle est au ledger.
        $this->clarify('une intention floue');

        $row = AiProviderInvocation::query()->firstOrFail();

        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(RuntimeException::class, $row->failure_reason);
        // 0 != inconnu : rien n'a ete observe, rien ne devient zero.
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->total_tokens);
        $this->assertNull($row->provider_cost);
        $this->assertNull($row->currency);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_source);
        // L'appel est parti avec le credential du tenant : prouve meme en echec.
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);
    }

    public function test_two_calls_of_one_operation_are_two_lines_never_deduplicated(): void
    {
        $this->fakeClarifier(count: 2);

        AiCorrelation::start();

        $this->clarify('premier appel de la meme operation');
        $this->clarify('second appel de la meme operation');

        $rows = AiProviderInvocation::query()->get();

        $this->assertCount(2, $rows);
        // Meme parent metier…
        $this->assertSame(1, $rows->pluck('correlation_id')->unique()->count());
        // …mais deux invocations irreductibles : ids et invocations SDK distincts.
        $this->assertSame(2, $rows->pluck('id')->unique()->count());
        $this->assertSame(2, $rows->pluck('sdk_invocation_id')->unique()->count());
    }

    public function test_a_pre_provider_refusal_writes_no_invocation(): void
    {
        // Cas 1 : aucune configuration IA tenant -> repli deterministe.
        OrganizationAiSetting::query()->delete();
        HelpRequestClarifierAgent::fake(function (): never {
            throw new RuntimeException('The SDK must not be called at all.');
        });

        $this->clarify('sans configuration tenant');

        $this->assertSame(0, AiProviderInvocation::query()->count());

        // Cas 2 : budget mensuel deja consomme dans ai_interactions
        // (l'autorite economique actuelle) -> refus AVANT tout appel.
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => self::API_KEY,
        ]);
        AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'help_request.clarify',
            'feature' => 'clarify_help_request',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'x',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => 999.0,
            'cost_unknown' => false,
        ]);

        $this->clarify('budget deja epuise');

        // La garde a lu ai_interactions (pas le ledger) et a refuse : aucune
        // tentative provider, donc aucune ligne canonique.
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_each_tenant_invocation_carries_its_own_organization(): void
    {
        $other = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $other->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-or-other-org',
        ]);
        $otherMember = User::factory()->create(['organization_id' => $other->id]);

        $this->fakeClarifier(count: 2);
        $this->clarify('appel du tenant A');

        app()->instance('current_organization', $other);
        $otherLoop = (new LoopService)->createLoop($otherMember, 'Boucle TASK1220 B');
        app(ClarifyUserHelpRequestService::class)->clarifyForLoop($otherLoop, $otherMember, 'appel du tenant B');
        app()->instance('current_organization', $this->organization);

        $this->assertSame(
            1,
            AiProviderInvocation::query()->where('organization_id', $this->organization->id)->count(),
        );
        $this->assertSame(
            1,
            AiProviderInvocation::query()->where('organization_id', $other->id)->count(),
        );
        $this->assertSame(
            (string) $otherMember->id,
            (string) AiProviderInvocation::query()->where('organization_id', $other->id)->value('user_id'),
        );
    }

    public function test_the_ledger_never_contains_any_credential(): void
    {
        $this->fakeClarifier();

        $this->clarify('verifions les secrets');

        $row = AiProviderInvocation::query()->firstOrFail();
        $serialized = json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::API_KEY, $serialized);
        $this->assertStringNotContainsString('platform-key-never-recorded', $serialized);
        // Ni prompt ni reponse : le ledger est economique, pas un journal de contenu.
        $this->assertArrayNotHasKey('prompt', $row->getAttributes());
        $this->assertArrayNotHasKey('response', $row->getAttributes());
    }

    public function test_the_p1_trace_and_the_ledger_both_receive_their_line(): void
    {
        $this->fakeClarifier();

        $this->clarify('double registre, autorites distinctes');

        // Le ledger s'AJOUTE : la trace P1 (autorite de AiEconomicGuard et de
        // la console TASK-1219) recoit toujours exactement sa ligne.
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // B. Contrats du ledger (0 != inconnu, provenance, credential)
    // =====================================================================

    public function test_total_tokens_is_not_fabricated_from_a_partial_observation(): void
    {
        $row = $this->ledger()->recordGeneration(
            organizationId: (string) $this->organization->id,
            userId: null,
            capability: null,
            process: 'clarify',
            resolved: new ResolvedModel('openai', 'gpt-4o-mini'),
            usage: AiUsage::of(10, null),
            cost: null,
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: null,
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: null,
        );

        $this->assertSame(10, $row->input_tokens);
        $this->assertNull($row->output_tokens);
        // Une moitie manquante ne fabrique pas un total.
        $this->assertNull($row->total_tokens);
    }

    public function test_a_real_zero_cost_is_known_and_distinct_from_unknown(): void
    {
        $zero = $this->ledger()->recordGeneration(
            organizationId: (string) $this->organization->id,
            userId: null,
            capability: null,
            process: 'clarify',
            resolved: new ResolvedModel('ollama', 'llama3'),
            usage: AiUsage::of(5, 5),
            cost: AiCost::known(0.0, AiCost::SOURCE_CATALOG_ESTIMATED),
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: null,
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: null,
        );

        $this->assertSame(AiProviderInvocation::COST_KNOWN, $zero->cost_status);
        $this->assertSame(0.0, (float) $zero->provider_cost);
        $this->assertSame('USD', $zero->currency);

        $unknown = $this->ledger()->recordGeneration(
            organizationId: (string) $this->organization->id,
            userId: null,
            capability: null,
            process: 'clarify',
            resolved: new ResolvedModel('openai', 'model-not-priced'),
            usage: AiUsage::of(5, 5),
            cost: AiCost::unknown(AiPricingCatalog::REASON_MODEL_NOT_IN_CATALOG),
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: null,
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: null,
        );

        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $unknown->cost_status);
        $this->assertNull($unknown->provider_cost);
        $this->assertNull($unknown->currency);
    }

    public function test_a_known_cost_without_declared_provenance_keeps_source_unknown(): void
    {
        $row = $this->ledger()->recordGeneration(
            organizationId: (string) $this->organization->id,
            userId: null,
            capability: null,
            process: 'clarify',
            resolved: new ResolvedModel('openai', 'gpt-4o-mini'),
            usage: AiUsage::of(1, 1),
            cost: AiCost::known(0.25),
            status: AiProviderInvocation::STATUS_SUCCESS,
            correlationId: null,
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: null,
        );

        // On connait le montant, pas sa provenance : les deux affirmations
        // restent independantes.
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_source);
    }

    public function test_cost_provenance_is_declared_by_the_two_primitives_that_know(): void
    {
        // Le catalogue declare son estimation…
        $catalog = AiPricingCatalog::cost('openai', 'gpt-4o-mini', AiUsage::of(1_000_000, 0));
        $this->assertSame(AiCost::SOURCE_CATALOG_ESTIMATED, $catalog->source);

        // …et la garde declare un cout rapporte par le provider.
        $reported = app(AiEconomicGuard::class)->finalize('openai', 'gpt-4o-mini', AiUsage::of(1, 1), 0.12);
        $this->assertSame(AiCost::SOURCE_PROVIDER_REPORTED, $reported->source);
        $this->assertSame(0.12, $reported->costUsd);
    }

    public function test_credential_source_is_unknown_for_an_unregistered_instance(): void
    {
        $row = $this->ledger()->recordGeneration(
            organizationId: (string) $this->organization->id,
            userId: null,
            capability: null,
            process: 'clarify',
            resolved: new ResolvedModel('openai', 'gpt-4o-mini', 'some-instance-nobody-registered'),
            usage: AiUsage::notObserved(),
            cost: null,
            status: AiProviderInvocation::STATUS_FAILED,
            correlationId: null,
            sdkInvocationId: null,
            failureReason: null,
            startedAtMicrotime: null,
        );

        $this->assertSame(AiProviderInvocation::CREDENTIAL_UNKNOWN, $row->credential_source);
    }

    public function test_credential_source_is_none_for_a_keyless_driver(): void
    {
        config([
            'ai.providers.ollama.driver' => 'ollama',
            'ai.providers.ollama.url' => 'http://127.0.0.1:11434',
        ]);
        OrganizationAiSetting::query()
            ->where('organization_id', $this->organization->id)
            ->update(['provider' => 'ollama', 'model' => 'llama3', 'api_key' => null]);

        $contexte = new ContexteIa(
            organizationId: (string) $this->organization->id,
            userId: (string) $this->member->id,
            loopId: $this->loop->id,
            locale: 'fr',
            capability: 'clarify_help_request',
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_USER_LOOPS,
        );

        $resolved = app(ProviderResolver::class)->resolve('clarify_help_request', $contexte);

        // Il est DEMONTRABLE qu'aucun credential n'existe pour un driver
        // keyless : `none`, pas `unknown`.
        $this->assertSame(
            AiProviderInvocation::CREDENTIAL_NONE,
            ProviderResolver::credentialSourceFor($resolved->instance),
        );
    }

    // =====================================================================
    // C. Embeddings — ingestion et query, via le flux produit reel
    // =====================================================================

    public function test_article_ingestion_writes_an_ingestion_invocation(): void
    {
        [$dossier, $post] = $this->dossierFixture();
        $this->enableSemanticGate();
        $this->fakeEmbeddings();

        app(DossierArticleIndexer::class)->synchronize($this->organization->id, $dossier->id, $post->id);

        $row = AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->firstOrFail();

        $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_INGESTION, $row->embedding_operation);
        $this->assertSame((string) $this->organization->id, (string) $row->organization_id);
        $this->assertSame('dossier.embeddings_index', $row->process);
        $this->assertSame('openai', $row->provider);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        // Le SDK ne fournit qu'un TOTAL pour les embeddings : pas de
        // repartition inventee.
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNotNull($row->total_tokens);
        $this->assertGreaterThan(0, $row->total_tokens);
        $this->assertNotNull($row->embedding_count);
        $this->assertSame($this->fakeEmbeddingDimensions(), $row->embedding_dimensions);
        // Instance tenant enregistree par resolveEmbeddingInstance : prouve.
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);
        $this->assertNotNull($row->sdk_invocation_id);

        // La trace admin historique recoit toujours SA ligne : le ledger s'ajoute.
        $this->assertSame(1, AdminAiInteraction::query()->where('scenario_id', 'dossier_embeddings_index')->count());
    }

    public function test_semantic_query_writes_a_query_invocation_distinct_from_ingestion(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Dossier semantic search requires PostgreSQL pgvector.');
        }

        [$dossier, $post] = $this->dossierFixture();
        $this->enableSemanticGate();
        $this->fakeEmbeddings();

        // 1. Une ingestion reelle…
        app(DossierArticleIndexer::class)->synchronize($this->organization->id, $dossier->id, $post->id);

        // 2. …puis une query reelle sur le meme perimetre, avec l'instance tenant.
        $instance = app(ProviderResolver::class)->resolveEmbeddingInstance((string) $this->organization->id);
        $this->assertNotNull($instance);

        app(DossierSemanticSearchService::class)->searchAcrossDossiers(
            (string) $this->organization->id,
            [(string) $dossier->id],
            'que contient ce dossier ?',
            3,
            $instance,
            ['capability' => 'loop_knowledge_answer'],
        );

        $rows = AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_INGESTION, $rows[0]->embedding_operation);
        $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $rows[1]->embedding_operation);
        $this->assertSame('dossier.embeddings_search', $rows[1]->process);
        $this->assertSame('loop_knowledge_answer', $rows[1]->capability);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $rows[1]->credential_source);
        $this->assertSame(1, $rows[1]->embedding_count);
    }

    public function test_a_failed_embedding_writes_a_failed_line_with_null_measures(): void
    {
        [$dossier, $post] = $this->dossierFixture();
        $this->enableSemanticGate();
        $this->configureEmbeddingsProvider();

        Embeddings::fake(function (): never {
            throw new RuntimeException('embedding provider down');
        })->preventStrayEmbeddings();

        try {
            app(DossierArticleIndexer::class)->synchronize($this->organization->id, $dossier->id, $post->id);
            $this->fail('The indexer should propagate the provider failure.');
        } catch (RuntimeException) {
            // comportement fonctionnel inchange : l'echec remonte.
        }

        $row = AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->firstOrFail();

        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_INGESTION, $row->embedding_operation);
        $this->assertNull($row->total_tokens);
        $this->assertNull($row->embedding_count);
        $this->assertNull($row->embedding_dimensions);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        // La tentative est partie sur l'instance tenant : prouve, meme en echec.
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);
        $this->assertNotNull($row->sdk_invocation_id);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function ledger(): AiProviderInvocationLedger
    {
        return app(AiProviderInvocationLedger::class);
    }

    private function clarify(string $phrase): void
    {
        app(ClarifyUserHelpRequestService::class)->clarifyForLoop($this->loop, $this->member, $phrase);
    }

    private function fakeClarifier(int $inputTokens = 10, int $outputTokens = 5, int $count = 1): void
    {
        $structured = [
            'title' => 'Demande clarifiee',
            'clarified_request' => 'Je cherche de l’aide pour un projet europeen.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggestion_reason' => '',
            'suggested_category_id' => '',
            'questions' => [],
        ];

        HelpRequestClarifierAgent::fake(array_map(
            fn (): StructuredTextResponse => new StructuredTextResponse(
                $structured,
                json_encode($structured, JSON_UNESCAPED_UNICODE),
                new Usage($inputTokens, $outputTokens),
                new Meta('openai', 'gpt-4o-mini'),
            ),
            range(1, $count),
        ));
    }

    /**
     * @return array{0: Dossier, 1: BlogPost}
     */
    private function dossierFixture(): array
    {
        $dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'TASK1220 dossier '.Str::uuid(),
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'title' => 'TASK1220 article '.Str::uuid(),
            'slug' => 'task1220-article-'.Str::uuid(),
            'content' => '<p>contenu indexable du ledger canonique</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->member->id,
            'position' => 1,
        ]);

        return [$dossier, $post];
    }

    private function enableSemanticGate(): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', array_unique(array_merge(
            config('ai.dossiers.semantic_search.organization_ids', []),
            [$this->organization->id],
        )));
    }

    private function configureEmbeddingsProvider(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', $this->fakeEmbeddingDimensions());
    }

    private function fakeEmbeddings(): void
    {
        $this->configureEmbeddingsProvider();
        $dimensions = $this->fakeEmbeddingDimensions();

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($dimensions): EmbeddingsResponse {
            $vectors = array_map(
                fn (): array => array_fill(0, $dimensions, 0.1),
                $prompt->inputs,
            );

            return new EmbeddingsResponse(
                $vectors,
                count($prompt->inputs) * 3,
                new Meta($prompt->provider->name(), $prompt->model),
            );
        })->preventStrayEmbeddings();
    }

    private function fakeEmbeddingDimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }
}
