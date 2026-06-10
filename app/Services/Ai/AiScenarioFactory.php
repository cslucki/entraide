<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiScenarioDefinition;

class AiScenarioFactory
{
    /** @var array<string, AiScenarioDefinition> */
    private array $scenarios = [];

    public function register(AiScenarioDefinition $scenario): void
    {
        $this->scenarios[$scenario->id()] = $scenario;
    }

    public function resolve(string $id): ?AiScenarioDefinition
    {
        return $this->scenarios[$id] ?? null;
    }

    /**
     * @return array<string, AiScenarioDefinition>
     */
    public function all(): array
    {
        return $this->scenarios;
    }
}
