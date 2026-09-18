<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent SDK de `loop_conversation_knowledge` (TASK-1534).
 *
 * Meme forme que `LoopKnowledgeAgent` : une classe dediee pour rester fakeable
 * par les tests (`Ai::fakeAgent(static::class)`), qui ne porte QUE ses
 * instructions composees et ses limites de generation. Le provider, le modele
 * et le credential viennent du `ProviderResolver` de l'Organization.
 *
 * Ce que cet agent fait, et qu'aucun autre ne faisait : compiler ce que des
 * HUMAINS se sont dit en une connaissance durable. Les agents existants
 * repondent a une question ; celui-ci n'a pas d'interlocuteur — sa sortie
 * n'est vue par personne au moment ou elle est produite. Elle est rangee, et
 * attendue.
 *
 * Il n'a ni outil, ni memoire, ni ecriture : la persistance appartient au
 * service appelant, qui seul connait la provenance et les droits de la source.
 */
final class LoopConversationKnowledgeAgent implements Agent
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
