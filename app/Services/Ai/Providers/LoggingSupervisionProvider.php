<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Logging\AiBenchmarkLogger;

class LoggingSupervisionProvider implements SupervisionProvider
{
    public function __construct(
        private readonly SupervisionProvider $inner,
        private readonly AiBenchmarkLogger $logger,
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        $startedAt = microtime(true) * 1000;

        $result = $this->inner->supervise($content, $model);

        $this->logger->log([
            'timestamp' => now()->toIso8601String(),
            'scenario_id' => 'supervision_content',
            'model' => $model ?? config('ai.openai.model'),
            'input_content' => $content,
            'output' => $result->output,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_ms' => round(microtime(true) * 1000 - $startedAt, 2),
            'cost_usd' => $result->estimatedCostUsd,
        ]);

        return $result;
    }
}
