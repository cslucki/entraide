<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * TASK-1437 — SW-7 : l'agent du Shell Welcome. Instructions = le prompt
 * d'accueil EN BASE (SW-2) + le contexte PUBLIC de l'Organization (SW-5) +
 * la langue ; `maxTokens` = la borne de sortie posee par la garde (SW-6),
 * jamais fournie par le visiteur. Texte simple, aucun outil, aucune ecriture.
 */
final class GuestShellAgent implements Agent
{
    use Promptable;

    public function __construct(
        private readonly string $composedInstructions,
        private readonly int $maxTokens,
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
