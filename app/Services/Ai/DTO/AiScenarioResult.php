<?php

namespace App\Services\Ai\DTO;

use App\Services\Ai\Contracts\AiScenarioDefinition;

final class AiScenarioResult
{
    public function __construct(
        public readonly AiSupervisionResult $supervisionResult,
        public readonly ?string $scenarioId = null,
        public readonly ?array $scenarioMeta = null,
        public readonly ?float $executionTimeMs = null,
        public readonly ?int $promptTokensUsed = null,
        public readonly ?int $completionTokensUsed = null,
    ) {}

    public static function fromSupervisionResult(
        AiSupervisionResult $result,
        AiScenarioDefinition $definition,
        ?float $executionTimeMs = null,
    ): self {
        return new self(
            supervisionResult: $result,
            scenarioId: $definition->id(),
            scenarioMeta: [
                'name' => $definition->name(),
                'description' => $definition->description(),
                'provider_hint' => $definition->providerHint(),
            ],
            executionTimeMs: $executionTimeMs,
            promptTokensUsed: $result->inputTokens,
            completionTokensUsed: $result->outputTokens,
        );
    }

    public function toArray(): array
    {
        return [
            ...$this->supervisionResult->toArray(),
            'scenario_id' => $this->scenarioId,
            'scenario_meta' => $this->scenarioMeta,
            'execution_time_ms' => $this->executionTimeMs,
            'prompt_tokens_used' => $this->promptTokensUsed,
            'completion_tokens_used' => $this->completionTokensUsed,
        ];
    }
}
