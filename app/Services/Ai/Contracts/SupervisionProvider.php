<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTO\AiSupervisionResult;

interface SupervisionProvider
{
    public function supervise(string $content, ?string $model = null): AiSupervisionResult;

    public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array;
}
