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
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        $startedAt = microtime(true) * 1000;

        $result = $this->inner->supervise($content, $model);

        $endMs = round(microtime(true) * 1000, 2);
        $latencyMs = round($endMs - $startedAt, 2);

        $this->logger->log([
            'timestamp' => now()->toIso8601String(),
            'scenario_id' => 'supervision_content',
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_ms' => $latencyMs,
            'cost_usd' => $result->estimatedCostUsd,
            'content_length' => mb_strlen($content),
            'status' => 'success',
        ]);

        $this->persistence->persist([
            'scenario_id' => 'supervision_content',
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
            'latency_ms' => (int) $latencyMs,
            'cost_usd' => $result->estimatedCostUsd,
        ]);

        return $result;
    }

    public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
    {
        $startedAt = microtime(true) * 1000;

        $result = $this->inner->runScenario($scenario, $content, $model);

        $endMs = round(microtime(true) * 1000, 2);
        $latencyMs = round($endMs - $startedAt, 2);

        $this->logger->log([
            'timestamp' => now()->toIso8601String(),
            'scenario_id' => $scenario->id(),
            'model' => $model,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => $latencyMs,
            'cost_usd' => 0.0,
            'content_length' => mb_strlen($content),
            'status' => 'success',
        ]);

        $this->persistence->persist([
            'scenario_id' => $scenario->id(),
            'model' => $model,
            'status' => 'success',
            'input_excerpt' => $content,
            'input_length' => mb_strlen($content),
            'result_summary' => $result['title'] ?? ($result['summary'] ?? null),
            'result_payload' => $result,
            'metadata' => [
                'scenario_class' => $scenario::class,
            ],
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => (int) $latencyMs,
            'cost_usd' => 0.0,
        ]);

        return $result;
    }
}
