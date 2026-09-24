<?php

namespace App\Support\AssignData;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1631 — un dataset de l'outil assign-data.
 *
 * Volontairement une valeur simple, pas un framework. Sa raison d'etre est
 * qu'il n'existe QU'UNE definition : le compteur du tableau, la vue detail,
 * la previsualisation et l'UPDATE lisent tous `query()` et `classification`
 * d'ici. L'outil historique avait quatre chemins qui recalculaient chacun
 * leurs conditions — et ils avaient diverge.
 *
 * `$columns` est une liste BLANCHE. L'ancien `sanitizeRow()` faisait
 * l'inverse : `toArray()` puis retrait de treize noms sensibles connus. Tout
 * ce qui n'etait pas dans cette liste noire etait expose — y compris une
 * colonne ajoutee demain a une table que personne ne relira. Ici, une
 * colonne non declaree n'est jamais rendue.
 */
class Dataset
{
    /**
     * @param  string  $key  identifiant stable, utilise dans les URLs
     * @param  string  $table  la table reelle ; c'est elle que la garde de
     *                         couverture confronte au schema
     * @param  list<string>  $columns  liste BLANCHE des colonnes affichables
     * @param  bool  $critical  une affectation ici demande une confirmation
     *                          ecrite supplementaire
     */
    public function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly DatasetClassification $classification,
        public readonly array $columns,
        public readonly bool $critical = false,
    ) {}

    public function labelKey(): string
    {
        return 'admin.assign_data.dataset_'.$this->key;
    }

    public function descriptionKey(): string
    {
        return 'admin.assign_data.dataset_'.$this->key.'_desc';
    }

    /**
     * LA requete du dataset. Une seule, pour les quatre usages.
     *
     * Volontairement le query builder et non Eloquent : l'outil doit voir la
     * table TELLE QU'ELLE EST, sans scope global de tenant, sans soft-delete
     * filtre, sans accesseur. Un Dossier soft-delete ou un article archive
     * porte un `organization_id` comme les autres, et le cacher fausserait
     * le diagnostic — c'est meme la raison pour laquelle le registre
     * historique melangeait `Model::withoutGlobalScopes()` et `DB::table()`
     * sans regle.
     */
    public function query(): Builder
    {
        return DB::table($this->table);
    }

    public function isAssignable(): bool
    {
        return $this->classification->isAssignable();
    }
}
