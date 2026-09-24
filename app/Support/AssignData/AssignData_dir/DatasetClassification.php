<?php

namespace App\Support\AssignData;

/**
 * TASK-1631 — ce que `organization_id IS NULL` veut dire, table par table.
 *
 * L'outil historique traitait 38 de ses 40 datasets comme « assignable », y
 * compris des referentiels partages ou NULL est la valeur NORMALE. Rattacher
 * une de ces lignes n'aurait rien repare : cela aurait supprime la version
 * globale — et, pour `loop_type_settings` et `translation_overrides`, viole
 * un index unique partiel que le schema pose exactement pour proteger la
 * ligne globale.
 *
 * D'ou ces cinq valeurs. Elles ne decrivent pas une preference d'affichage :
 * elles disent ce qu'un NULL SIGNIFIE dans cette table, et c'est de la que
 * decoule le droit de le reecrire.
 */
enum DatasetClassification: string
{
    /**
     * Donnee tenant. Un NULL est du legacy d'avant le tenancy : il se
     * rattache. C'est la SEULE classification qui ouvre une mutation.
     */
    case AssignableTenant = 'assignable_tenant';

    /**
     * Referentiel ou reglage partage : NULL signifie « Plateforme ».
     * Le rattacher casserait le produit.
     */
    case GlobalNullValid = 'global_null_valid';

    /** Journal : un NULL est une trace, on la garde telle quelle. */
    case HistoryNullValid = 'history_null_valid';

    /** Montre, jamais offert a l'affectation faute de semantique claire. */
    case DiagnosticOnly = 'diagnostic_only';

    /**
     * Colonne NOT NULL : `organization_id IS NULL` y est structurellement
     * impossible. L'outil n'a rien a y faire.
     */
    case Excluded = 'excluded';

    public function isAssignable(): bool
    {
        return $this === self::AssignableTenant;
    }

    /** La cle de traduction du badge, sans `match` recopie dans chaque vue. */
    public function labelKey(): string
    {
        return 'admin.assign_data.classification_'.$this->value;
    }

    public function hintKey(): string
    {
        return 'admin.assign_data.hint_'.$this->value;
    }
}
