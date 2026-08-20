<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use Carbon\CarbonImmutable;
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
 * TASK-1222 — autorite economique V1 : generation + embeddings.
 *
 * La regle centrale, prouvee et non contournable :
 *
 *   GENERATION  = `ai_interactions` (le registre que AiEconomicGuard garde) ;
 *   EMBEDDINGS  = ledger canonique  (`operation = embedding`) ;
 *   et JAMAIS la somme des deux registres pour une meme generation — une
 *   generation moderne, presente dans les deux, ne compte qu'UNE fois.
 *
 * Plus les invariants herites : 0 != inconnu, unknown compte jamais somme,
 * total NULL sans mesure reelle, refus pre-provider sans consommation, refus
 * budgetaire d'ingestion sans destruction d'index.
 */
class TASK1222EconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1222',
        ]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->from = CarbonImmutable::now()->startOfMonth();
        $this->to = $this->from->addMonth();

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Read model : les deux registres, chacun a sa place
    // =====================================================================

    public function test_generation_comes_from_ai_interactions_with_the_1219_semantics(): void
    {
        $this->generationTrace(costUsd: 0.10, costUnknown: false);
        $this->generationTrace(costUsd: null, costUnknown: true);
        $this->generationTrace(costUsd: null, costUnknown: null);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);

        $this->assertSame(0.10, round((float) $summary['generation']['known_cost_usd'], 6));
        $this->assertSame(1, $summary['generation']['measured_count']);
        $this->assertSame(1, $summary['generation']['unknown_count']);
        $this->assertSame(1, $summary['generation']['unevaluated_count']);
        $this->assertSame(3, $summary['generation']['trace_count']);
        // Le « jamais evalue » remonte au total : son absence se lirait
        // « tout est mesure ».
        $this->assertSame(1, $summary['total_unevaluated_count']);
    }

    public function test_embeddings_come_from_the_ledger_split_by_operation(): void
    {
        $this->ledgerEmbedding('ingestion', knownCost: 0.002);
        $this->ledgerEmbedding('ingestion', knownCost: null);
        $this->ledgerEmbedding('query', knownCost: 0.0001);
        $this->ledgerEmbedding('query', knownCost: null, status: AiProviderInvocation::STATUS_FAILED);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);

        $this->assertSame(0.002, round((float) $summary['embedding_ingestion']['known_cost_usd'], 6));
        $this->assertSame(1, $summary['embedding_ingestion']['unknown_count']);
        $this->assertSame(2, $summary['embedding_ingestion']['invocation_count']);

        $this->assertSame(0.0001, round((float) $summary['embedding_query']['known_cost_usd'], 6));
        // L'echec n'est PAS un « inconnu » economique : il a son compteur.
        $this->assertSame(0, $summary['embedding_query']['unknown_count']);
        $this->assertSame(1, $summary['embedding_query']['failed_count']);
    }

    public function test_a_modern_generation_present_in_both_registries_counts_once(): void
    {
        // Un parcours clarify REEL : il ecrit `ai_interactions` ET le ledger.
        $this->prepareClarify();
        $this->fakeClarifier(inputTokens: 1_000_000, outputTokens: 0);
        app()->instance('current_organization', $this->organization);
        $loop = (new LoopService)->createLoop($this->member, 'Boucle 1222');
        app(ClarifyUserHelpRequestService::class)->clarifyForLoop($loop, $this->member, 'du texte a clarifier');

        // Preuve de la double presence…
        $this->assertSame(1, AiInteraction::query()->count());
        $this->assertSame(1, AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_GENERATION)->count());

        // …et du comptage UNIQUE : 1M tokens input de gpt-4o-mini = 0.15 USD,
        // pas 0.30.
        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);
        $this->assertSame(0.15, round((float) $summary['generation']['known_cost_usd'], 6));
        $this->assertSame(0.15, round((float) $summary['total_known_cost_usd'], 6));

        // Meme unicite dans le plafond budgetaire du guard.
        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization);
        $this->assertSame(0.15, round($verdict->knownMonthlyCostUsd, 6));
    }

    public function test_generation_and_embeddings_add_up_without_overlap(): void
    {
        $this->generationTrace(costUsd: 0.10, costUnknown: false);
        $this->ledgerEmbedding('ingestion', knownCost: 0.02);
        $this->ledgerEmbedding('query', knownCost: 0.005);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);

        $this->assertSame(0.125, round((float) $summary['total_known_cost_usd'], 6));
        $this->assertSame(0, $summary['total_unknown_count']);
    }

    public function test_a_real_zero_total_differs_from_no_measure_at_all(): void
    {
        // Aucune mesure : NULL, pas 0.
        $empty = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);
        $this->assertNull($empty['total_known_cost_usd']);

        // Un vrai zero mesure : 0.0, pas NULL.
        $this->generationTrace(costUsd: 0.0, costUnknown: false);
        $zero = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);
        $this->assertSame(0.0, (float) $zero['total_known_cost_usd']);
    }

    public function test_unknown_costs_are_counted_never_summed(): void
    {
        $this->generationTrace(costUsd: null, costUnknown: true);
        $this->ledgerEmbedding('ingestion', knownCost: null);
        $this->ledgerEmbedding('query', knownCost: null);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);

        $this->assertNull($summary['total_known_cost_usd']);
        $this->assertSame(3, $summary['total_unknown_count']);
    }

    public function test_the_window_and_the_tenant_are_strict(): void
    {
        // Hors fenetre : mois precedent.
        $old = $this->generationTrace(costUsd: 9.0, costUnknown: false);
        $old->forceFill(['created_at' => $this->from->subDay()])->saveQuietly();
        $oldLedger = $this->ledgerEmbedding('ingestion', knownCost: 9.0);
        $oldLedger->forceFill(['created_at' => $this->from->subDay()])->saveQuietly();

        // Autre tenant.
        $other = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $other->id]);
        AiInteraction::create([
            'user_id' => $otherUser->id, 'organization_id' => $other->id, 'process' => 'help_request.clarify',
            'feature' => 'clarify_help_request', 'model' => 'openai/gpt-4o-mini', 'prompt' => 'x',
            'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 5.0, 'cost_unknown' => false,
        ]);
        AiProviderInvocation::create([
            'organization_id' => $other->id, 'operation' => 'embedding', 'embedding_operation' => 'query',
            'provider_cost' => 5.0, 'cost_status' => 'known', 'cost_source' => 'catalog_estimated',
            'currency' => 'USD', 'status' => 'success', 'credential_source' => 'organization',
        ]);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);

        $this->assertNull($summary['total_known_cost_usd']);
        $this->assertSame(0, $summary['generation']['trace_count']);
        $this->assertSame(0, $summary['embedding_ingestion']['invocation_count']);
        $this->assertSame(0, $summary['embedding_query']['invocation_count']);
    }

    // =====================================================================
    // B. Guard : plafond org generation + embeddings, quota unknown
    // =====================================================================

    public function test_embeddings_now_count_toward_the_organization_ceiling_for_generations(): void
    {
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 1.00]);
        // Le budget est deja consomme UNIQUEMENT par des embeddings.
        $this->ledgerEmbedding('ingestion', knownCost: 1.50);

        $verdict = app(AiEconomicGuard::class)->authorize(
            $this->organization->fresh(),
            'help_request.clarify',
            'openai',
            'gpt-4o-mini',
            100.0,
            100,
        );

        $this->assertFalse($verdict->allowed);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
        $this->assertSame(1.50, round($verdict->knownMonthlyCostUsd, 6));
    }

    public function test_embedding_ingestion_is_refused_when_the_ceiling_is_reached_by_generations(): void
    {
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 1.00]);
        $this->generationTrace(costUsd: 2.00, costUnknown: false);

        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization->fresh());

        $this->assertFalse($verdict->allowed);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
    }

    public function test_under_budget_embeddings_are_allowed(): void
    {
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 10.00]);
        $this->generationTrace(costUsd: 0.50, costUnknown: false);
        $this->ledgerEmbedding('query', knownCost: 0.10);

        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization->fresh());

        $this->assertTrue($verdict->allowed);
        $this->assertSame(0.60, round($verdict->knownMonthlyCostUsd, 6));
    }

    public function test_too_many_unknown_embedding_costs_are_refused_not_treated_as_free(): void
    {
        config(['ai.embeddings.economic_guard.monthly_unknown_limit' => 2]);
        $this->ledgerEmbedding('ingestion', knownCost: null);
        $this->ledgerEmbedding('query', knownCost: null);

        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization->fresh());

        $this->assertFalse($verdict->allowed);
        $this->assertSame(AiEconomicGuard::REASON_EMBEDDING_UNKNOWN_QUOTA_REACHED, $verdict->reason);
        $this->assertSame(2, $verdict->successfulUnknownCount);
    }

    // =====================================================================
    // C. Ingestion reelle : pricing, refus conservateur, no-P4 intact
    // =====================================================================

    public function test_a_real_ingestion_now_has_a_known_catalog_cost(): void
    {
        $this->enableSemanticGate();
        $this->fakeEmbeddings();
        [$dossier, $post] = $this->dossierFixture();

        app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);

        $row = AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->firstOrFail();

        // TASK-1222 : text-embedding-3-small est au catalogue (0.02 / 1M).
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        $this->assertSame(AiCost::SOURCE_CATALOG_ESTIMATED, $row->cost_source);
        $this->assertNotNull($row->total_tokens);
        $this->assertSame(
            round($row->total_tokens * 0.02 / 1_000_000, 8),
            round((float) $row->provider_cost, 8),
        );
        $this->assertSame('USD', $row->currency);
    }

    public function test_a_budget_refused_ingestion_applies_the_staleness_doctrine_without_any_call(): void
    {
        $this->enableSemanticGate();
        $this->fakeEmbeddings();
        [$dossier, $post] = $this->dossierFixture();

        // 1. Indexation initiale sous budget : l'index VALIDE existe.
        app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);
        $this->assertGreaterThan(0, DossierChunk::query()->count());

        // 1bis. Tant que le contenu ne change pas, le refus budgetaire ne peut
        // PAS toucher l'index : alreadyIndexed court-circuite avant la garde.
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 0.00]);
        Embeddings::fake(function (): never {
            throw new RuntimeException('No provider call is allowed over budget.');
        })->preventStrayEmbeddings();

        app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);
        $this->assertGreaterThan(0, DossierChunk::query()->count(), 'A valid index is never destroyed by budget.');

        // 2. Le contenu CHANGE (updateQuietly : l'observer relancerait sinon
        // sa propre reindexation sync avant nos asserts) : l'ancienne
        // representation est PERIMEE — meme doctrine que TASK-1214, un
        // paragraphe supprime par son auteur ne survit pas dans la recherche
        // pour raison de budget.
        $post->updateQuietly(['content' => '<p>nouveau contenu qui devrait etre reindexe</p>']);

        $ledgerBefore = AiProviderInvocation::query()->count();
        $returned = app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);

        $this->assertSame(0, DossierChunk::query()->count());
        $this->assertSame(0, $returned);
        // Aucun appel provider, aucune ligne fictive au ledger.
        $this->assertSame($ledgerBefore, AiProviderInvocation::query()->count());
    }

    public function test_no_p4_applies_the_same_staleness_doctrine_for_its_own_reason(): void
    {
        $this->enableSemanticGate();
        $this->fakeEmbeddings();
        [$dossier, $post] = $this->dossierFixture();

        app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);
        $this->assertGreaterThan(0, DossierChunk::query()->count());

        // Contenu change + credential DISPARU : la doctrine TASK-1214 reste —
        // l'ancien index ne doit plus etre servi comme s'il etait a jour.
        $post->updateQuietly(['content' => '<p>contenu change sans credential</p>']);
        OrganizationAiSetting::query()->delete();

        Embeddings::fake(function (): never {
            throw new RuntimeException('No platform fallback.');
        })->preventStrayEmbeddings();

        app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);

        $this->assertSame(0, DossierChunk::query()->count());
    }

    public function test_the_unknown_quota_ignores_failed_invocations(): void
    {
        // Une panne provider (x retries de job) ne doit pas fermer
        // l'ingestion du mois : l'echec a son compteur, pas celui de l'inconnu.
        config(['ai.embeddings.economic_guard.monthly_unknown_limit' => 2]);
        $this->ledgerEmbedding('ingestion', knownCost: null, status: AiProviderInvocation::STATUS_FAILED);
        $this->ledgerEmbedding('ingestion', knownCost: null, status: AiProviderInvocation::STATUS_FAILED);
        $this->ledgerEmbedding('ingestion', knownCost: null, status: AiProviderInvocation::STATUS_FAILED);

        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization->fresh());

        $this->assertTrue($verdict->allowed);
        $this->assertSame(0, $verdict->successfulUnknownCount);

        // Et le read model les rend en `failed_count`, pas en inconnu.
        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);
        $this->assertSame(0, $summary['total_unknown_count']);
        $this->assertSame(3, $summary['embedding_ingestion']['failed_count']);
    }

    public function test_an_explicit_model_rate_beats_a_generic_provider_override(): void
    {
        // Une surcharge generique openai (pensee pour la generation) ne doit
        // pas facturer les embeddings 7,5x leur tarif releve.
        config(['ai_pricing.overrides.openai' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60]]);

        $cost = AiPricingCatalog::cost('openai', 'text-embedding-3-small', AiUsage::of(1_000_000, null));
        $this->assertSame(0.02, round((float) $cost->costUsd, 6));

        // La surcharge reste l'ultime recours d'un modele HORS catalogue.
        $fallback = AiPricingCatalog::cost('openai', 'gpt-4o', AiUsage::of(1_000_000, null));
        $this->assertSame(0.15, round((float) $fallback->costUsd, 6));
    }

    public function test_the_openrouter_embedding_model_id_is_priced(): void
    {
        // La clef catalogue porte l'identifiant OpenRouter REEL (prefixe
        // openai/), celui que le listener recevra du SDK.
        $cost = AiPricingCatalog::cost('openrouter', 'openai/text-embedding-3-small', AiUsage::of(500_000, null));
        $this->assertTrue($cost->isKnown());
        $this->assertSame(0.01, round((float) $cost->costUsd, 6));
    }

    public function test_an_undeclared_embedding_operation_is_still_counted_in_the_total(): void
    {
        // Une invocation embedding sans operation declaree compte au plafond
        // du guard : elle doit apparaitre dans le total du read model, dans
        // son seau « non declaree » — jamais nulle part.
        AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => null,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_UNKNOWN,
            'provider_cost' => 0.03,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => AiCost::SOURCE_CATALOG_ESTIMATED,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);
        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization->fresh());

        $this->assertSame(0.03, round((float) $summary['embedding_undeclared']['known_cost_usd'], 6));
        $this->assertSame(0.03, round((float) $summary['total_known_cost_usd'], 6));
        // Le guard et le read model racontent le MEME argent.
        $this->assertSame(0.03, round($verdict->knownMonthlyCostUsd, 6));
    }

    public function test_a_zero_or_empty_unknown_limit_falls_back_to_the_default(): void
    {
        // Une variable d'env presente mais vide vaut (int) 0 : elle ne doit
        // jamais signifier « zero ingestion autorisee pour toujours ».
        config(['ai.embeddings.economic_guard.monthly_unknown_limit' => 0]);

        $verdict = app(AiEconomicGuard::class)->authorizeEmbeddings($this->organization->fresh());

        $this->assertTrue($verdict->allowed);
    }

    public function test_the_semantic_query_paths_are_guarded_by_the_same_ceiling(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Semantic search requires PostgreSQL pgvector.');
        }

        $this->enableSemanticGate();
        $this->fakeEmbeddings();
        [$dossier, $post] = $this->dossierFixture();
        app(DossierArticleIndexer::class)->synchronize((string) $this->organization->id, (string) $dossier->id, (string) $post->id);

        // Budget atteint : la recherche ne peut plus emettre d'embedding.
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 0.00]);
        Embeddings::fake(function (): never {
            throw new RuntimeException('No query embedding is allowed over budget.');
        })->preventStrayEmbeddings();

        $ledgerBefore = AiProviderInvocation::query()->count();

        $rows = app(DossierSemanticSearchService::class)->searchAcrossDossiers(
            (string) $this->organization->id,
            [(string) $dossier->id],
            'une question sur le corpus',
            3,
        );

        $this->assertSame([], $rows);
        $this->assertSame($ledgerBefore, AiProviderInvocation::query()->count());
    }

    public function test_two_invocations_of_one_correlation_both_count_economically(): void
    {
        $correlation = (string) Str::uuid();
        $this->ledgerEmbedding('query', knownCost: 0.01, correlationId: $correlation);
        $this->ledgerEmbedding('query', knownCost: 0.01, correlationId: $correlation);

        $summary = $this->usage()->summary((string) $this->organization->id, $this->from, $this->to);

        // correlation = operation metier, pas cle de dedup : 2 invocations,
        // 2 couts.
        $this->assertSame(2, $summary['embedding_query']['invocation_count']);
        $this->assertSame(0.02, round((float) $summary['embedding_query']['known_cost_usd'], 6));
    }

    public function test_the_summary_never_contains_a_credential(): void
    {
        $this->generationTrace(costUsd: 0.10, costUnknown: false);
        $this->ledgerEmbedding('ingestion', knownCost: 0.01);

        $serialized = json_encode(
            $this->usage()->summary((string) $this->organization->id, $this->from, $this->to),
            JSON_UNESCAPED_UNICODE,
        );

        $this->assertStringNotContainsString('sk-task1222', $serialized);
        $this->assertStringNotContainsString('api_key', $serialized);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function usage(): OrganizationAiEconomicUsage
    {
        return app(OrganizationAiEconomicUsage::class);
    }

    private function generationTrace(?float $costUsd, ?bool $costUnknown): AiInteraction
    {
        $interaction = AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'help_request.clarify',
            'feature' => 'clarify_help_request',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'x',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_usd' => $costUsd,
            'cost_unknown' => $costUnknown,
        ]);

        // TASK-1260 : jumelle ledger — l'autorite generation de la garde
        // depuis le cutover (les releves, eux, lisent toujours
        // `ai_interactions` : les assertions de summary() ne changent pas).
        // Une trace `cost_unknown = NULL` figure l'historique d'avant P1-2,
        // anterieur au ledger : pas de jumelle.
        if ($costUnknown !== null) {
            AiProviderInvocation::create([
                'organization_id' => $this->organization->id,
                'user_id' => $this->member->id,
                'process' => 'help_request.clarify',
                'operation' => AiProviderInvocation::OPERATION_GENERATION,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
                'provider_cost' => $costUnknown ? null : $costUsd,
                'currency' => $costUnknown ? null : 'USD',
                'cost_status' => $costUnknown ? AiProviderInvocation::COST_UNKNOWN : AiProviderInvocation::COST_KNOWN,
                'cost_source' => $costUnknown ? 'unknown' : 'catalog_estimated',
                'status' => AiProviderInvocation::STATUS_SUCCESS,
                'correlation_id' => (string) Str::uuid(),
            ]);
        }

        return $interaction;
    }

    private function ledgerEmbedding(
        string $embeddingOperation,
        ?float $knownCost,
        string $status = AiProviderInvocation::STATUS_SUCCESS,
        ?string $correlationId = null,
    ): AiProviderInvocation {
        return AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'process' => 'dossier.embeddings_index',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => $embeddingOperation,
            'provider' => 'openai',
            'model' => 'text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $knownCost,
            'currency' => $knownCost !== null ? 'USD' : null,
            'cost_status' => $knownCost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $knownCost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => $status,
            'correlation_id' => $correlationId,
        ]);
    }

    private function prepareClarify(): void
    {
        AiConfig::set('clarification_enabled', true);
        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 2)
            ->update(['prompt_text' => 'Reformule sans inventer.', 'is_active' => true]);
        config([
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);
    }

    private function fakeClarifier(int $inputTokens, int $outputTokens): void
    {
        $structured = [
            'title' => 'Demande', 'clarified_request' => 'Une demande.', 'help_type' => 'information',
            'suggested_loop_id' => '', 'suggestion_reason' => '', 'suggested_category_id' => '', 'questions' => [],
        ];

        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structured,
                json_encode($structured),
                new Usage($inputTokens, $outputTokens),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);
    }

    /**
     * @return array{0: Dossier, 1: BlogPost}
     */
    private function dossierFixture(): array
    {
        $dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'TASK1222 dossier '.Str::uuid(),
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'title' => 'TASK1222 article '.Str::uuid(),
            'slug' => 'task1222-article-'.Str::uuid(),
            'content' => '<p>contenu economique indexable</p>',
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

    private function fakeEmbeddings(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        $dimensions = config('database.default') === 'pgsql' ? 1536 : 8;
        config()->set('ai.providers.openai.models.embeddings.dimensions', $dimensions);

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($dimensions): EmbeddingsResponse {
            $vectors = array_map(fn (): array => array_fill(0, $dimensions, 0.1), $prompt->inputs);

            return new EmbeddingsResponse($vectors, count($prompt->inputs) * 3, new Meta($prompt->provider->name(), $prompt->model));
        })->preventStrayEmbeddings();
    }
}
