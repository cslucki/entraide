<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAllowedException;

/**
 * Garde-fou unique (TASK-1240, contrat TASK-1239 S3) : verifie que
 * l'Organization cible figure dans l'allowlist declarative
 * `config('scenario_packs.allowed_organizations')`. Partage par
 * `ScenarioPackLoader`, `ScenarioPackResetter` et `ScenarioPackRemover` afin
 * qu'aucune des trois operations ne puisse, par oubli, s'appliquer a une
 * Organization non qualifiee.
 */
class ScenarioPackOrganizationGuard
{
    public static function assertAllowed(Organization $organization): void
    {
        $allowed = config('scenario_packs.allowed_organizations', []);

        if (! in_array($organization->slug, $allowed, true)) {
            throw ScenarioPackOrganizationNotAllowedException::forSlug($organization->slug);
        }
    }
}
