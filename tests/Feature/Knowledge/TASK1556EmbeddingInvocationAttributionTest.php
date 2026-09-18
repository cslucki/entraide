<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\ChatLoop\LoopSummary;
use App\Services\Dossiers\DossierChunkEmbeddingService;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1556 — un tour (`ai_interactions`) dit EXACTEMENT quelle invocation
 * embedding il a declenchee, comme il dit deja sa generation.
 *
 * Harnais de TASK-1554 (recherche mockee, agent fake, SQLite). Le mock de
 * recherche rejoue ce que `searchAcrossDossiers()` fait reellement autour de
 * l'appel provider : meme `TRACE_CONTEXT_KEY`, meme `embed()`, donc les VRAIS
 * evenements SDK, le vrai listener, la vraie ligne de ledger.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1556EmbeddingInvocationAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        RecordSdkEmbeddingsInvocation::forgetJournal();

        $this->organization = Organization::factory()->create(['locale' => 'fr']);
        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier ARIA',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1556',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 8,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        $this->fakeEmbeddings();
    }

    // ───────────────────────────────── 1. la generation reste attribuable

    public function test_la_generation_reste_jointe_exactement_au_ledger(): void
    {
        $this->rechercheQuiEmbedde();
        $this->agentRepond('ARIA signifie ARtistic Intelligence Alliance [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'C\'est quoi ARIA ?');

        $tour = AiInteraction::findOrFail($reponse->interactionId);
        $generation = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_GENERATION)->sole();

        $this->assertArrayHasKey('sdk_invocation_id', $tour->metadata);
        $this->assertSame($generation->sdk_invocation_id, $tour->metadata['sdk_invocation_id']);
    }

    // ───────────────────────────────── 2. l'embedding devient attribuable

    public function test_l_embedding_du_tour_est_joint_exactement_au_ledger(): void
    {
        $this->rechercheQuiEmbedde();
        $this->agentRepond('ARIA signifie ARtistic Intelligence Alliance [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'C\'est quoi ARIA ?');

        $tour = AiInteraction::findOrFail($reponse->interactionId);
        $embedding = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)->sole();

        $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $embedding->embedding_operation);
        $this->assertArrayHasKey(RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY, $tour->metadata);
        $this->assertSame([$embedding->sdk_invocation_id], $tour->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY]);
        $this->assertTrue(Str::isUuid($embedding->sdk_invocation_id));
        $this->assertNotSame($tour->metadata['sdk_invocation_id'], $embedding->sdk_invocation_id,
            'generation et embedding sont deux invocations distinctes');
    }

    // ───────────────────────────────── 3. meme correlation, deux tours

    public function test_deux_tours_sous_la_meme_correlation_ne_se_melangent_pas(): void
    {
        $correlation = AiCorrelation::start();
        $this->rechercheQuiEmbedde();
        $this->agentRepond('Reponse un [S1].');
        $premier = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question un ?');

        $this->agentRepond('Reponse deux [S1].');
        $second = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question deux ?');

        $tours = AiInteraction::whereIn('id', [$premier->interactionId, $second->interactionId])->get()->keyBy('id');
        $embeddings = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->orderBy('started_at')->orderBy('created_at')->pluck('sdk_invocation_id')->all();

        $this->assertCount(2, $embeddings);
        $this->assertSame($correlation, $tours[$premier->interactionId]->correlation_id);
        $this->assertSame($correlation, $tours[$second->interactionId]->correlation_id,
            'la correlation est bien partagee : elle ne peut pas servir de cle de tour');

        $duPremier = $tours[$premier->interactionId]->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY];
        $duSecond = $tours[$second->interactionId]->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY];

        $this->assertCount(1, $duPremier);
        $this->assertCount(1, $duSecond);
        $this->assertNotSame($duPremier, $duSecond);
        $this->assertEqualsCanonicalizing($embeddings, [...$duPremier, ...$duSecond]);
    }

    // ───────────────────────────────── 4. tour sans embedding

    public function test_un_tour_sans_embedding_porte_une_liste_vide_jamais_un_id_emprunte(): void
    {
        $this->rechercheSansEmbedding();
        $this->agentRepond('Reponse [S1].');

        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $tour = AiInteraction::findOrFail($reponse->interactionId);

        $this->assertArrayHasKey(RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY, $tour->metadata,
            'la cle est PRESENTE : « aucun embedding » est une mesure, pas une absence');
        $this->assertSame([], $tour->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY]);
    }

    // ───────────────────────────────── 5. aucune invocation etrangere

    public function test_une_ingestion_ou_un_autre_tenant_ne_sont_jamais_rattaches(): void
    {
        $autre = Organization::factory()->create();

        AiCorrelation::start();
        // Une ingestion de CE tenant.
        $this->embedderHorsTour((string) $this->organization->id, AiProviderInvocation::EMBEDDING_OPERATION_INGESTION);
        // Une recherche d'un AUTRE tenant, avec son identite de tour.
        $this->embedderHorsTour((string) $autre->id, AiProviderInvocation::EMBEDDING_OPERATION_QUERY, 'q', null, ['turn_id' => (string) Str::uuid()]);
        // Une recherche de CE tenant, meme correlation, mais un AUTRE tour.
        $this->embedderHorsTour((string) $this->organization->id, AiProviderInvocation::EMBEDDING_OPERATION_QUERY, 'q', null, ['turn_id' => (string) Str::uuid()]);

        $etrangeres = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)->pluck('sdk_invocation_id')->all();
        $this->assertCount(3, $etrangeres);

        $this->rechercheQuiEmbedde();
        $this->agentRepond('Reponse [S1].');
        $reponse = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $tour = AiInteraction::findOrFail($reponse->interactionId);
        $laSienne = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->whereNotIn('sdk_invocation_id', $etrangeres)->sole();

        $this->assertSame((string) $this->organization->id, (string) $laSienne->organization_id);
        $this->assertSame([$laSienne->sdk_invocation_id], $tour->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY]);
        foreach ($etrangeres as $etrangere) {
            $this->assertNotContains($etrangere, $tour->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY]);
        }
    }

    // ───────────────────────────────── 6. le ledger ne bouge pas

    public function test_le_ledger_recoit_exactement_les_memes_lignes_qu_avant(): void
    {
        $this->rechercheQuiEmbedde();
        $this->agentRepond('Reponse [S1].');

        $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question ?');

        $lignes = AiProviderInvocation::orderBy('created_at')->get();
        $this->assertCount(2, $lignes);
        $this->assertSame(
            [AiProviderInvocation::OPERATION_EMBEDDING, AiProviderInvocation::OPERATION_GENERATION],
            $lignes->pluck('operation')->sort()->values()->all(),
        );
        $embedding = $lignes->firstWhere('operation', AiProviderInvocation::OPERATION_EMBEDDING);
        $this->assertSame((string) $this->organization->id, (string) $embedding->organization_id);
        $this->assertSame('dossier.embeddings_search', $embedding->process);
        $this->assertSame(1, $embedding->embedding_count);
        $this->assertSame(8, $embedding->embedding_dimensions);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $embedding->status);
    }

    // ───────────────────────────────── 7. metadata historique

    public function test_une_ligne_historique_sans_la_cle_reste_lisible(): void
    {
        $historique = AiInteraction::create([
            'user_id' => $this->proprietaire->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'dossier.answer',
            'feature' => 'loop_knowledge_answer',
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'metadata' => ['sdk_invocation_id' => (string) Str::uuid(), 'provider' => 'openrouter'],
        ]);

        $resume = LoopSummary::fromInteraction($historique->fresh());

        $this->assertSame($historique->metadata['sdk_invocation_id'], $resume->sdkInvocationId);
        $this->assertArrayNotHasKey(RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY, $historique->fresh()->metadata,
            'une ligne anterieure au mecanisme n a PAS la cle : absence ≠ liste vide');
    }

    // ───────────────────────────────── 8. l'echec d'embedding appartient au tour

    public function test_une_tentative_d_embedding_echouee_reste_journalisee_pour_le_tour_suivant(): void
    {
        $organizationId = (string) $this->organization->id;
        $turnId = (string) Str::uuid();

        Embeddings::fake(function (): never {
            throw new \RuntimeException('embedding provider down');
        })->preventStrayEmbeddings();

        try {
            $this->embedderHorsTour($organizationId, AiProviderInvocation::EMBEDDING_OPERATION_QUERY, 'q', null, ['turn_id' => $turnId]);
            $this->fail('l echec doit remonter');
        } catch (\RuntimeException) {
        }

        $echec = AiProviderInvocation::where('status', AiProviderInvocation::STATUS_FAILED)->sole();
        $this->assertSame(
            [$echec->sdk_invocation_id],
            RecordSdkEmbeddingsInvocation::claimQueryInvocationIds($organizationId, $turnId),
        );
        $this->assertSame([], RecordSdkEmbeddingsInvocation::claimQueryInvocationIds($organizationId, $turnId),
            'reclamee une seule fois');
    }

    // ───────────────────────────────── 9. tour interrompu : rien ne fuit

    public function test_l_embedding_d_un_tour_interrompu_ne_fuit_pas_vers_le_tour_suivant_sans_embedding(): void
    {
        AiCorrelation::start();

        // Tour A : embedde la question, ne trouve rien, n'ecrit AUCUNE interaction.
        $this->rechercheQuiEmbeddeSansRienTrouver();
        $a = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question sans source ?');
        $this->assertNull($a->interactionId);
        $this->assertCount(1, AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)->get());

        // Tour B : meme tenant, meme correlation, meme processus, SANS embedding.
        $this->rechercheSansEmbedding();
        $this->agentRepond('Reponse B [S1].');
        $b = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question B ?');

        $tourB = AiInteraction::findOrFail($b->interactionId);
        $this->assertSame([], $tourB->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY],
            'B n a rien embedde : l invocation de A ne lui appartient pas');
    }

    public function test_l_embedding_d_un_tour_interrompu_ne_fuit_pas_vers_le_tour_suivant_avec_embedding(): void
    {
        AiCorrelation::start();

        $this->rechercheQuiEmbeddeSansRienTrouver();
        $a = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question sans source ?');
        $this->assertNull($a->interactionId);
        $deA = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)->sole()->sdk_invocation_id;

        $this->rechercheQuiEmbedde();
        $this->agentRepond('Reponse B [S1].');
        $b = $this->service()->answer($this->organization, $this->dossier, $this->proprietaire, 'Question B ?');

        $tourB = AiInteraction::findOrFail($b->interactionId);
        $deB = AiProviderInvocation::where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('sdk_invocation_id', '!=', $deA)->sole()->sdk_invocation_id;

        $this->assertSame([$deB], $tourB->metadata[RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY],
            'B ne porte que SON invocation, jamais celle de A');
    }

    // ───────────────────────────────────────────────────────── fixtures

    private function service(): DossierInsightsService
    {
        return app(DossierInsightsService::class);
    }

    private function agentRepond(string $texte): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * Rejoue autour de l'appel provider EXACTEMENT ce que
     * `DossierSemanticSearchService::searchAcrossDossiers()` fait : trace
     * posee, `embed()` reel (SDK fake), trace retiree. Seule la partie SQL
     * pgvector est remplacee par une ligne fixe.
     */
    private function rechercheQuiEmbedde(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturnUsing(
            function (string $organizationId, array $dossierIds, string $query, string $instance, int $limit = 5, array $traceMetadata = []): array {
                $this->embedderHorsTour($organizationId, AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $query, $instance, $traceMetadata);

                return [$this->ligne('A')];
            },
        );
    }

    /**
     * Le tour INTERROMPU : la recherche embedde reellement la question, puis ne
     * trouve rien — `answer()` rend « aucune source » sans ecrire d'interaction.
     */
    private function rechercheQuiEmbeddeSansRienTrouver(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturnUsing(
            function (string $organizationId, array $dossierIds, string $query, string $instance, int $limit = 5, array $traceMetadata = []): array {
                $this->embedderHorsTour($organizationId, AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $query, $instance, $traceMetadata);

                return [];
            },
        );
    }

    private function rechercheSansEmbedding(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([$this->ligne('A')]);
    }

    /**
     * @param  array<string, mixed>  $traceMetadata  ce que l'appelant de
     *                                               `searchAcrossDossiers()` transmet
     */
    private function embedderHorsTour(string $organizationId, string $operation, string $texte = 'texte', ?string $instance = null, array $traceMetadata = []): void
    {
        Context::add(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY, [
            'organization_id' => $organizationId,
            'scenario_id' => $operation === AiProviderInvocation::EMBEDDING_OPERATION_QUERY
                ? 'dossier_embeddings_search'
                : 'dossier_embeddings_index',
            'embedding_operation' => $operation,
            'metadata' => array_merge(['dossier_id' => $this->dossier->id], $traceMetadata),
        ]);

        try {
            app(DossierChunkEmbeddingService::class)->embed([$texte], $instance ?? 'openrouter');
        } finally {
            Context::forget(RecordSdkEmbeddingsInvocation::TRACE_CONTEXT_KEY);
        }
    }

    private function fakeEmbeddings(): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt): EmbeddingsResponse {
            return new EmbeddingsResponse(
                array_map(fn (): array => array_fill(0, 8, 0.1), $prompt->inputs),
                count($prompt->inputs) * 3,
                new Meta($prompt->provider->name(), $prompt->model),
            );
        })->preventStrayEmbeddings();
    }

    /** @return array<string, mixed> Forme rendue par `searchAcrossDossiers()`. */
    private function ligne(string $etiquette): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Document '.$etiquette,
            'slug' => 'document-'.strtolower($etiquette),
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => 0,
            'content' => "Contenu {$etiquette} : ARIA signifie ARtistic Intelligence Alliance.",
            'distance' => 0.12,
        ];
    }
}
