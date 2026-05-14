<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTO\AssistedInteractionLabResult;

interface AiProvider
{
    public function analyze(string $phrase): AssistedInteractionLabResult;
}
