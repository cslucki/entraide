<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * TASK-1526 — generation texte de la reponse generale du Shell membre.
 *
 * L'agent ne porte ni fil, ni outil, ni source documentaire, ni ecriture :
 * `AiShellResponder` reste l'unique autorite conversationnelle et le service
 * de capability lui fournit les instructions composees et les bornes.
 */
final class ShellGeneralAnswerAgent implements Agent
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
