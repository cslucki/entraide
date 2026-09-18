<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent SDK du protocole de patch (TASK-1540).
 *
 * Meme forme que `LoopConversationKnowledgeAgent`, et une difference qui change
 * tout : il ne rend pas un texte a ranger, il rend des OPERATIONS a valider.
 *
 * Le serveur n'a donc plus a faire confiance a ce qui revient. Une identite
 * inventee, une preuve hors perimetre, un enonce vide : chacun est rejete
 * individuellement, sans faire tomber le reste du patch. C'est ce qui permet
 * de corriger un fait sans reecrire toute la memoire — et d'accepter huit
 * operations sur dix.
 */
final class LoopClaimPatchAgent implements Agent
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
