<?php

namespace App\Listeners;

use App\Models\AdminAiInteraction;
use App\Support\Ai\AiCorrelation;
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

    public function handle(GeneratingEmbeddings|EmbeddingsGenerated $event): void
    {
        if ($event instanceof GeneratingEmbeddings) {
            $this->markPending($event->invocationId);

            return;
        }

        $usage = AiUsage::of($event->response->tokens, null);
        $cost = AiPricingCatalog::cost($event->provider->name(), $event->model, $usage);

        $this->write(
            invocationId: $event->invocationId,
            provider: $event->provider->name(),
            model: $event->model,
            status: 'success',
            latencyMs: $this->consumePending($event->invocationId),
            inputTokens: $usage->inputTokensOrZero(),
            outputTokens: $usage->outputTokensOrZero(),
            resultPayload: [
                'embedding_count' => count($event->response->embeddings),
                'dimensions' => $event->response->embeddings === []
                    ? 0
                    : count($event->response->embeddings[0]),
            ],
            costAttributes: $cost->traceAttributes(),
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
    public static function recordFailure(?string $provider, ?string $model): void
    {
        $listener = new self;
        [$invocationId, $latencyMs] = $listener->consumeLastPending();

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
        );
    }

    private function markPending(string $invocationId): void
    {
        $pending = Context::get(self::PENDING_CONTEXT_KEY, []);
        $pending[$invocationId] = ['started_at' => microtime(true)];
        Context::add(self::PENDING_CONTEXT_KEY, $pending);
    }

    private function consumePending(string $invocationId): ?int
    {
        $pending = Context::get(self::PENDING_CONTEXT_KEY, []);
        $startedAt = $pending[$invocationId]['started_at'] ?? null;

        unset($pending[$invocationId]);
        Context::add(self::PENDING_CONTEXT_KEY, $pending);

        return $startedAt === null ? null : $this->elapsedMs($startedAt);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function consumeLastPending(): array
    {
        $pending = Context::get(self::PENDING_CONTEXT_KEY, []);

        if ($pending === []) {
            return [null, null];
        }

        $invocationId = array_key_last($pending);
        $startedAt = $pending[$invocationId]['started_at'];

        unset($pending[$invocationId]);
        Context::add(self::PENDING_CONTEXT_KEY, $pending);

        return [$invocationId, $this->elapsedMs($startedAt)];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
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
    ): void {
        $trace = Context::get(self::TRACE_CONTEXT_KEY);

        if (! is_array($trace) || ! isset($trace['organization_id'], $trace['scenario_id'])) {
            Log::warning('RecordSdkEmbeddingsInvocation: no trace context, invocation not recorded.', [
                'invocation_id' => $invocationId,
            ]);

            return;
        }

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
