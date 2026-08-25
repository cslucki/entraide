<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * TASK-1233 : agent SDK des capabilities canoniques `loop_answer` et
 * `loop_ask` (« Demander a l'IA » dans une Boucle) — meme forme que
 * `LoopSummaryAgent` : il ne porte que les instructions COMPOSEES
 * (Constitution -> doctrine de l'Organization -> prompt administrable) et les
 * bornes de generation. Aucun prompt metier ici : la composition est celle
 * de `PromptRepository`, le contexte celui du `ContextBuilder`.
 */
final class LoopDirectAnswerAgent implements Agent
{
    use Promptable;

    public function __construct(
        private readonly string $composedInstructions,
        private readonly ?int $maxTokens = null,
        private readonly ?float $temperature = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->composedInstructions;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }
}
