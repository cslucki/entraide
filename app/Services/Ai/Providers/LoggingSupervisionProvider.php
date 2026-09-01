<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;

class LoggingSupervisionProvider implements SupervisionProvider
{
    public function __construct(
        private readonly SupervisionProvider $inner,
        private readonly AiBenchmarkLogger $logger,
        private readonly AdminAiInteractionPersistence $persistence,
        private readonly string $providerName = 'unknown',
        /**
         * TASK-1250 : tenant EXPLICITE de la trace `admin_ai_interactions`.
         * NULL = resolution historique de la persistence (`current_organization`,
         * sinon l'Organization de l'utilisateur connecte).
         */
        private readonly ?string $organizationId = null,
    ) {}

    /**
     * TASK-1250 : la meme instance, dont la trace operationnelle est imputee
     * au tenant donne — celui du perimetre economique — plutot qu'a un
     * contexte implicite.
     */
    public function forTenant(string $organizationId): self
    {
        return new self($this->inner, $this->logger, $this->persistence, $this->providerName, $organizationId);
    }

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        $startedAt = microtime(true) * 1000;

        $result = $this->inner->supervise($content, $model);

        $elapsedMs = round((microtime(true) * 1000) - $startedAt, 2);
        $latencyMs = (int) round($elapsedMs);

        $this->logger->log([
            'timestamp' => now()->toIso8601String(),
            'scenario_id' => 'supervision_content',
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_ms' => $elapsedMs,
            'cost_usd' => $result->estimatedCostUsd,
            'cost_unknown' => $result->costUnknown,
            'content_length' => mb_strlen($content),
            'status' => 'success',
        ]);

        $this->persistence->persist([
            'organization_id' => $this->organizationId,
            'scenario_id' => 'supervision_content',
            'provider' => $this->providerName,
            'model' => $result->model,
            'status' => 'success',
            'input_excerpt' => $content,
            'input_length' => mb_strlen($content),
            'result_summary' => $result->summary,
            'result_payload' => $result->toArray(),
            'metadata' => [
                'risk_level' => $result->riskLevel,
                'needs_human_category_review' => $result->needsHumanCategoryReview,
                'moderation_flag' => $result->moderationFlag,
            ],
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_ms' => $latencyMs,
            'cost_usd' => $result->estimatedCostUsd,
            'cost_unknown' => $result->costUnknown,
        ]);

        return $result;
    }

    /**
     * TASK-1132 — pourquoi le coût est ici structurellement inconnu.
     *
     * Le contrat `SupervisionProvider::runScenario()` renvoie uniquement le
     * payload parsé du scénario : aucun provider n'y expose son bloc `usage`
     * (vérifié sur OpenAI, OpenRouter et Ollama). Sans usage observé, il n'y a
     * rien à multiplier par un tarif.
     *
     * Les valeurs `'cost_usd' => 0.0` et `'input_tokens' => 0` écrites ici
     * auparavant n'étaient donc pas des mesures : c'était un coût nul fabriqué,
     * indiscernable en base d'un appel réellement gratuit. On écrit désormais la
     * vérité : usage non renseigné et `cost_unknown = true`.
     *
     * Exposer l'usage de `runScenario` demanderait de changer le contrat du
     * provider — c'est le sujet de P1-3, pas de P1-2.
     */
    public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
    {
        $startedAt = microtime(true) * 1000;

        $result = $this->inner->runScenario($scenario, $content, $model);

        $elapsedMs = round((microtime(true) * 1000) - $startedAt, 2);
        $latencyMs = (int) round($elapsedMs);

        $this->logger->log([
            'timestamp' => now()->toIso8601String(),
            'scenario_id' => $scenario->id(),
            'model' => $model,
            'input_tokens' => null,
            'output_tokens' => null,
            'latency_ms' => $elapsedMs,
            'cost_usd' => null,
            'cost_unknown' => true,
            'content_length' => mb_strlen($content),
            'status' => 'success',
        ]);

        $this->persistence->persist([
            'organization_id' => $this->organizationId,
            'scenario_id' => $scenario->id(),
            'provider' => $this->providerName,
            'model' => $model,
            'status' => 'success',
            'input_excerpt' => $content,
            'input_length' => mb_strlen($content),
            'result_summary' => $result['title'] ?? ($result['summary'] ?? null),
            'result_payload' => $result,
            'metadata' => [
                'scenario_class' => $scenario::class,
            ],
            'latency_ms' => $latencyMs,
            'cost_usd' => null,
            'cost_unknown' => true,
        ]);

        return $result;
    }
}
