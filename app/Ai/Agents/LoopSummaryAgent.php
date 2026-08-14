<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent Laravel AI SDK de la capability `loop_summary` (TASK-1207 / IA P3).
 *
 * Une classe dediee plutot que `Laravel\Ai\agent()` pour une raison precise :
 * le fake officiel du SDK est indexe PAR NOM DE CLASSE D'AGENT
 * (`Ai::fakeAgent(static::class, ...)`, cf. `InteractsWithFakeAgents`).
 * Passer par `AnonymousAgent` ferait partager la meme cle de fake a tous les
 * agents anonymes du produit : un test ne pourrait plus affirmer que c'est
 * bien le resume de Boucle qui a ete invoque.
 *
 * L'agent ne porte QUE ses instructions et ses limites de generation. Il ne
 * compose pas son prompt (c'est `PromptRepository`), ne choisit pas son
 * provider (c'est `ProviderResolver`), ne declare ni outil, ni memoire, ni
 * recuperation documentaire : `loop_summary` reste un resume de la Boucle, pas
 * un chat generaliste.
 *
 * `maxTokens()` et `temperature()` sont lues par
 * `TextGenerationOptions::forAgent()` : elles transportent telles quelles les
 * limites du chemin legacy (`ai.chatloop.max_tokens`, `ai.chatloop.temperature`),
 * pour que la migration ne change pas la forme des reponses.
 */
final class LoopSummaryAgent implements Agent
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
