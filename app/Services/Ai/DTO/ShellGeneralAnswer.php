<?php

namespace App\Services\Ai\DTO;

/** TASK-1526 — resultat texte et pointeur de trace d'un tour general. */
final class ShellGeneralAnswer
{
    public function __construct(
        public readonly string $answer,
        public readonly string $interactionId,
    ) {}
}
