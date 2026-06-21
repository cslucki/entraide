<?php

namespace App\Services\Ai\Contracts;

interface AiScenarioDefinition
{
    public function id(): string;

    public function name(): string;

    public function description(): ?string;

    public function providerHint(): string;

    public function systemPrompt(): string;

    public function jsonSchema(): array;
}
