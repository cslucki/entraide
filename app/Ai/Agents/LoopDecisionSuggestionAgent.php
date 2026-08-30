<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent Laravel AI SDK de la capability `loop_decision_suggestion`
 * (TASK-1327 / Premium-1 « Decision Memory IA »).
 *
 * Une classe dediee et non `LoopSummaryAgent`, pour la meme raison que lui :
 * le fake officiel du SDK est indexe PAR NOM DE CLASSE D'AGENT
 * (`Ai::fakeAgent(static::class, ...)`). Partager la classe du resume rendrait
 * un test incapable d'affirmer que c'est bien la suggestion de Decision qui a
 * ete invoquee — et reciproquement.
 *
 * L'agent ne porte QUE ses instructions et ses limites de generation : le
 * prompt est compose par `PromptRepository`, le provider choisi par
 * `ProviderResolver`, le contexte fourni par le Context Builder. Il ne
 * declare ni outil, ni memoire, ni recuperation documentaire.
 */
final class LoopDecisionSuggestionAgent implements Agent
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
