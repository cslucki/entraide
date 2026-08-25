<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * Reset demande pour un pack qui n'a jamais ete charge dans cette
 * Organization : "retour a l'etat immediatement apres un chargement propre"
 * (contrat TASK-1239 S11) n'a pas de sens sans chargement prealable.
 *
 * A la difference du reset, la suppression (`ScenarioPackRemover`) est un
 * no-op silencieux dans ce cas — "retrait" de rien n'est pas une erreur.
 */
class ScenarioPackNotLoadedException extends RuntimeException
{
    public static function forPack(string $packId, string $organizationSlug): self
    {
        return new self("Le pack '{$packId}' n'a jamais ete charge dans l'Organization '{$organizationSlug}' : reset impossible.");
    }
}
