<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * Le pack a retrouve une entite PREEXISTANTE (il ne l'a pas creee) et l'a
 * modifiee au passage (typiquement `updateOrCreate` dont les valeurs
 * different de l'etat existant). Contrat TASK-1245 : une entite `reused`
 * est REFERENCEE par le pack, jamais mutee — sinon "STATE BEFORE -> LOAD ->
 * DELETE -> STATE BEFORE" est deja rompu au chargement, quoi que fasse
 * ensuite le remover. Pas de snapshot/restore en V1 : refus du chargement
 * (la transaction du loader annule la modification), collision explicite.
 * Le pack doit soit creer une entite qui lui est propre, soit ne pas
 * toucher a l'existante.
 */
class ScenarioPackReusedEntityMutatedException extends RuntimeException
{
    /** @param list<string> $changedAttributes */
    public static function forEntity(string $entityType, string $internalKey, string $modelClass, array $changedAttributes): self
    {
        $attributes = implode(', ', $changedAttributes);

        return new self(
            "Entite scenario pack '{$entityType}:{$internalKey}' ({$modelClass}) preexistait au chargement ".
            "et a ete modifiee par le pack (attributs : {$attributes}). Une entite reutilisee ne doit jamais ".
            'etre mutee : chargement refuse, aucune modification conservee.'
        );
    }
}
