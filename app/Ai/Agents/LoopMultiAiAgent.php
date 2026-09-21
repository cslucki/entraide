<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent SDK d'UN assistant du plugin « 3 assistants IA ». (TASK-1618)
 *
 * Meme forme que `LoopClaimPatchAgent` : un agent minimal, `fake()`-able, qui
 * ne porte QUE les instructions deja composees. Il ne compose rien lui-meme —
 * la hierarchie Constitution > Organization > capability > persona est tenue
 * par `PromptRepository`, en amont, et un agent qui recomposerait ouvrirait
 * une seconde porte sur cette hierarchie.
 *
 * Un seul agent pour les trois assistants : ce qui les distingue est le TEXTE
 * compose et le MODELE appele, pas la forme de l'appel.
 */
final class LoopMultiAiAgent implements Agent
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
