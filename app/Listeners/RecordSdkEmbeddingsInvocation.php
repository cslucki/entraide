<?php

namespace App\Listeners;

use App\Ai\ProviderResolver;
use App\Models\AdminAiInteraction;
use App\Models\AiProviderInvocation;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiUsage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;

/**
 * Instrumentation des invocations Embeddings du Laravel AI SDK
 * (TASK-1200 / IA P1-3).
 *
 * Seul couple d'événements avec un call site réel dans le produit
 * (`DossierChunkEmbeddingService::embed()`), voir le TASK pour l'audit complet
 * des 28 événements installés et pourquoi les autres ne sont pas branchés ici.
 *
 * Le SDK ne dispatche AUCUN événement d'échec : `EmbeddingsGenerated` est émis
 * par le SDK via `tap()` sur la réponse, jamais atteint si l'appel lève. Le
 * chemin d'échec passe donc par `recordFailure()`, appelé explicitement par le
 * call site depuis son `catch`, jamais par un événement.
 *
 * `organization_id` et `process` ne sont portés par aucun événement SDK (le
 * SDK ignore tout du domaine BouclePro) : l'appelant les dépose dans `Context`
 * juste avant d'appeler le SDK. Sans ce contexte, aucune ligne n'est écrite —
 * jamais de trace orpheline hors tenant.
 */
class RecordSdkEmbeddingsInvocation
{
    private const PENDING_CONTEXT_KEY = 'ai_sdk_pending_embeddings';

    public const TRACE_CONTEXT_KEY = 'ai_sdk_trace_context';

    /**
     * `org:{organizationId}:{famille}` -> `{famille}`. L'organizationId est un
     * UUID (sans deux-points), donc la famille est le segment apres le dernier
     * deux-points. Tout autre nom est rendu tel quel.
     */
    public static function normalizeProviderFamily(?string $name): ?string
    {
        if ($name === null || ! str_starts_with($name, 'org:')) {
            return $name;
        }

        $position = strrpos($name, ':');

        return $position === false ? $name : substr($name, $position + 1);
    }

    public function handle(GeneratingEmbeddings|EmbeddingsGenerated $event): void
    {
        if ($event instanceof GeneratingEmbeddings) {
            $this->markPending($event->invocationId);

            return;
        }

        $usage = AiUsage::of($event->response->tokens, null);
        // TASK-1214 : une invocation tenant part sur une instance nommee
        // `org:{id}:{famille}`. La trace et le catalogue de prix raisonnent par
        // FAMILLE (openai, openrouter…), pas par instance : sans normalisation,
        // le provider enregistre porterait l'id d'Organization (deja en
        // colonne) et le tarif ne serait jamais trouve. La preuve que l'instance
        // tenant a servi vit dans le registre pose par ProviderResolver au
        // moment ou il a enregistre l'instance (TASK-1220) : le lookup se fait
        // sur le nom BRUT, avant normalisation.
        $rawInstanceName = $event->provider->name();
        $provider = self::normalizeProviderFamily($rawInstanceName);
        $cost = AiPricingCatalog::cost($provider, $event->model, $usage);

        [$latencyMs, $startedAtMicrotime] = $this->consumePending($event->invocationId);

        $this->write(
            invocationId: $event->invocationId,
            provider: $provider,
            model: $event->model,
            status: 'success',
            latencyMs: $latencyMs,
            inputTokens: $usage->inputTokensOrZero(),
            outputTokens: $usage->outputTokensOrZero(),
            resultPayload: [
                'embedding_count' => count($event->response->embeddings),
                'dimensions' => $event->response->embeddings === []
                    ? 0
                    : count($event->response->embeddings[0]),
            ],
            costAttributes: $cost->traceAttributes(),
            credentialSource: ProviderResolver::credentialSourceFor($rawInstanceName),
            totalTokens: $event->response->tokens,
            embeddingCount: count($event->response->embeddings),
            embeddingDimensions: $event->response->embeddings === []
                ? null
                : count($event->response->embeddings[0]),
            cost: $cost,
            startedAtMicrotime: $startedAtMicrotime,
        );
    }

    /**
     * Chemin d'échec — jamais déclenché par un événement (aucun n'existe pour
     * ce cas), toujours par le call site depuis son `catch`.
     *
     * L'appelant ne peut PAS connaître l'`invocationId` : il est généré à
     * l'intérieur du SDK (`Str::uuid7()` dans `GeneratesEmbeddings::embeddings()`)
     * et n'est exposé qu'via les événements. On récupère donc la dernière
     * invocation restée en attente — correcte tant que les appels sont
     * séquentiels, jamais concurrents (vrai pour les deux call sites actuels,
     * synchrones l'un comme l'autre).
     */
    /**
     * `$instanceName` (TASK-1220) : nom BRUT de l'instance SDK reellement
     * invoquee (`org:{id}:{famille}` ou famille nue), transmis par le call
     * site — c'est la cle du registre de credential du ProviderResolver.
     * Absent chez un appelant historique : la source reste `unknown`.
     */
    public static function recordFailure(?string $provider, ?string $model, ?string $instanceName = null): void
    {
        $listener = new self;
        [$invocationId, $latencyMs, $startedAtMicrotime] = $listener->consumeLastPending();

        if ($invocationId === null) {
            Log::warning('RecordSdkEmbeddingsInvocation: failure with no pending invocation to attribute it to.');

            return;
        }

        $listener->write(
            invocationId: $invocationId,
            provider: $provider,
            model: $model,
            status: 'failed',
            latencyMs: $latencyMs,
            inputTokens: 0,
            outputTokens: 0,
            resultPayload: null,
            costAttributes: ['cost_usd' => null, 'cost_unknown' => true],
            credentialSource: ProviderResolver::credentialSourceFor($instanceName),
            totalTokens: null,
            embeddingCount: null,
            embeddingDimensions: null,
            cost: null,
            startedAtMicrotime: $startedAtMicrotime,
        );
    }

    private function markPending(string $invocationId): void
    {
        $pending = Context::get(self::PENDING_CONTEXT_KEY, []);
        $pending[$invocationId] = ['started_at' => microtime(true)];
        Context::add(self::PENDING_CONTEXT_KEY, $pending);
    }

    /**
     * @return array{0: ?int, 1: ?float} latence ms, depart absolu microtime
     */
    private function consumePending(string $invocationId): array
    {
        $pending = Context::get(self::PENDING_CONTEXT_KEY, []);
        $startedAt = $pending[$invocationId]['started_at'] ?? null;

        unset($pending[$invocationId]);
        Context::add(self::PENDING_CONTEXT_KEY, $pending);

        return $startedAt === null ? [null, null] : [$this->elapsedMs($startedAt), $startedAt];
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?float}
     */
    private function consumeLastPending(): array
    {
        $pending = Context::get(self::PENDING_CONTEXT_KEY, []);

        if ($pending === []) {
            return [null, null, null];
        }

        $invocationId = array_key_last($pending);
        $startedAt = $pending[$invocationId]['started_at'];

        unset($pending[$invocationId]);
        Context::add(self::PENDING_CONTEXT_KEY, $pending);

        return [$invocationId, $this->elapsedMs($startedAt), $startedAt];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Ecrit la trace admin historique ET la ligne canonique du ledger
     * `ai_provider_invocations` (TASK-1220). La double ecriture n'est pas un
     * double comptage : les deux registres ont des autorites distinctes et
     * aucune lecture ne les somme (voir AiProviderInvocationLedger).
     *
     * @param  array<string, mixed>|null  $resultPayload
     * @param  array{cost_usd: ?float, cost_unknown: bool}  $costAttributes
     */
    private function write(
        string $invocationId,
        ?string $provider,
        ?string $model,
        string $status,
        ?int $latencyMs,
        int $inputTokens,
        int $outputTokens,
        ?array $resultPayload,
        array $costAttributes,
        string $credentialSource = AiProviderInvocation::CREDENTIAL_UNKNOWN,
        ?int $totalTokens = null,
        ?int $embeddingCount = null,
        ?int $embeddingDimensions = null,
        ?AiCost $cost = null,
        ?float $startedAtMicrotime = null,
    ): void {
        $trace = Context::get(self::TRACE_CONTEXT_KEY);

        if (! is_array($trace) || ! isset($trace['organization_id'], $trace['scenario_id'])) {
            Log::warning('RecordSdkEmbeddingsInvocation: no trace context, invocation not recorded.', [
                'invocation_id' => $invocationId,
            ]);

            return;
        }

        $embeddingOperation = $trace['embedding_operation'] ?? null;
        $capability = $trace['metadata']['capability'] ?? null;

        app(AiProviderInvocationLedger::class)->recordEmbedding(
            organizationId: (string) $trace['organization_id'],
            userId: Auth::id() !== null ? (string) Auth::id() : null,
            capability: is_string($capability) ? $capability : null,
            process: AiProcess::fromScenarioId($trace['scenario_id']),
            embeddingOperation: is_string($embeddingOperation) ? $embeddingOperation : null,
            provider: $provider,
            model: $model,
            credentialSource: $credentialSource,
            totalTokens: $totalTokens,
            embeddingCount: $embeddingCount,
            embeddingDimensions: $embeddingDimensions,
            cost: $cost,
            status: $status,
            correlationId: AiCorrelation::id(),
            sdkInvocationId: $invocationId,
            startedAtMicrotime: $startedAtMicrotime,
        );

        AdminAiInteraction::create([
            'organization_id' => $trace['organization_id'],
            'user_id' => Auth::id(),
            'correlation_id' => AiCorrelation::id(),
            'process' => AiProcess::fromScenarioId($trace['scenario_id']),
            'scenario_id' => $trace['scenario_id'],
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'input_length' => 0,
            'result_payload' => $resultPayload,
            'metadata' => array_filter([
                'sdk_invocation_id' => $invocationId,
                ...($trace['metadata'] ?? []),
            ]),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            ...$costAttributes,
        ]);
    }
}
