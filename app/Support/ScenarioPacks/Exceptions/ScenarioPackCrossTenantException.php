<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * Une entite declaree au registrar porte un `organization_id` different de
 * celui du chargement en cours (bug dans une implementation de
 * ScenarioPackDefinition). Refus immediat : jamais silencieux (contrat
 * TASK-1239 S14).
 */
class ScenarioPackCrossTenantException extends RuntimeException
{
    public static function forEntity(string $entityType, string $internalKey, string $expectedOrganizationId, string $actualOrganizationId): self
    {
        return new self(
            "Entite scenario pack '{$entityType}:{$internalKey}' porte organization_id={$actualOrganizationId}, ".
            "attendu {$expectedOrganizationId}. Ecriture refusee."
        );
    }
}
