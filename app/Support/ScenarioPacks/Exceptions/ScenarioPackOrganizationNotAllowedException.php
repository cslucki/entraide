<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * L'Organization ciblee n'est pas dans l'allowlist
 * `config('scenario_packs.allowed_organizations')` (garde-fou TASK-1240,
 * contrat TASK-1239 S3). Refus systematique, avant toute ecriture.
 */
class ScenarioPackOrganizationNotAllowedException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self("L'Organization '{$slug}' n'est pas qualifiee comme cible de demonstration/dogfooding (config('scenario_packs.allowed_organizations')).");
    }
}
