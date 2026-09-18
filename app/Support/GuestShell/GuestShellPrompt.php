<?php

namespace App\Support\GuestShell;

/** TASK-1432 — SW-2 : un prompt d'accueil resolu, avec sa provenance (scenario, version, id) pour le ledger et l'admin. */
final class GuestShellPrompt
{
    public function __construct(
        public readonly string $scenarioId,
        public readonly int $version,
        public readonly string $promptId,
        public readonly string $text,
    ) {}
}
