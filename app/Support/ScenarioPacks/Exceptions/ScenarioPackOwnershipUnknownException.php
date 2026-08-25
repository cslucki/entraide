<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * Le chargement contient au moins une ligne de registre dont l'`ownership`
 * est inconnu (NULL : inscrite avant la migration TASK-1245). Deviner
 * `created` reviendrait a s'octroyer un droit de destruction non prouve ;
 * deviner `reused` laisserait des residus en silence. Refus explicite,
 * AVANT toute suppression partielle (jamais une purge a moitie faite suivie
 * de la destruction du registre). Le nettoyage d'un tel chargement est une
 * operation manuelle controlee, hors code produit (decision MASTER 1).
 */
class ScenarioPackOwnershipUnknownException extends RuntimeException
{
    /** @param array<string, int> $countsByType entity_type -> nombre de lignes a ownership inconnu */
    public static function forLoad(string $packId, string $organizationSlug, string $operation, array $countsByType): self
    {
        $total = array_sum($countsByType);
        $detail = implode(', ', array_map(
            fn (string $type, int $count) => "{$type}={$count}",
            array_keys($countsByType),
            $countsByType,
        ));

        return new self(
            "Le pack '{$packId}' charge dans l'Organization '{$organizationSlug}' contient {$total} entite(s) ".
            "a ownership inconnu ({$detail}) : {$operation} refuse. Aucune suppression n'a ete effectuee. ".
            'Ce chargement est anterieur a TASK-1245 ; il doit etre nettoye manuellement, jamais purge par supposition.'
        );
    }
}
