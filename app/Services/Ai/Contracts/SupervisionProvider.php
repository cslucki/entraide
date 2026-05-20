<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTO\AiSupervisionResult;

interface SupervisionProvider
{
    public function supervise(string $content): AiSupervisionResult;
}
