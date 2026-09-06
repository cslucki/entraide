<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * TASK-1351 — une Organization PREEXISTANTE porte deja de la donnee metier :
 * un pack ne l'adopte pas, ne l'ecrase pas, et ne s'y charge pas.
 *
 * Le cas vise n'est pas theorique : un slug de demonstration peut avoir ete
 * cree a la main, ou avoir servi a autre chose. Charger dedans melerait le
 * dataset du pack a des donnees dont personne ne connait plus l'origine, et le
 * retrait ulterieur ne saurait plus quoi supprimer. Le refus est explicite et
 * arrive AVANT toute ecriture.
 *
 * Une Organization vide reste chargeable (c'est l'etat normal apres un retrait
 * qui n'a pas supprime la coquille) : elle ne devient pas propriete du pack
 * pour autant.
 */
class ScenarioPackOrganizationNotAdoptableException extends RuntimeException
{
    /**
     * @param  array<string, int>  $contents  type de donnee => nombre de lignes trouvees
     */
    public static function forOrganization(string $slug, array $contents): self
    {
        $details = implode(', ', array_map(
            static fn (string $type, int $count): string => "{$type}: {$count}",
            array_keys($contents),
            array_values($contents),
        ));

        return new self(
            "L'Organization '{$slug}' existe deja et porte de la donnee metier ({$details}) : ".
            'un scenario pack ne peut ni l\'adopter ni l\'ecraser. Choisir un autre slug, ou vider cette Organization deliberement.'
        );
    }
}
