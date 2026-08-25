<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent SDK de `loop_knowledge_answer` (TASK-1213 / RAG V1).
 *
 * Meme forme que LoopSummaryAgent : une classe dediee pour rester fakeable par
 * les tests (`Ai::fakeAgent(static::class)`), qui ne porte QUE ses
 * instructions composees (Constitution → capability → AdminAiPrompt) et ses
 * limites de generation. Le provider, le modele et le credential viennent du
 * ProviderResolver de l'Organization ; le contexte documentaire borne vient
 * du Context Builder. L'agent n'a ni outil, ni memoire, ni ecriture.
 */
final class LoopKnowledgeAgent implements Agent
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
