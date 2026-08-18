<?php

namespace App\Support\ScenarioPacks;

use App\Models\ScenarioPackLoad;

/**
 * Resultat d'un chargement ou d'un reset (TASK-1240), pour affichage par les
 * commandes Artisan et, plus tard, l'UI Admin (TASK-1241).
 */
class ScenarioPackLoadResult
{
    /**
     * @param  array<string, int>  $entityCountsByType
     * @param  list<string>  $removedOrphans  "type|internal_key" retires (reset uniquement)
     */
    public function __construct(
        public readonly ScenarioPackLoad $load,
        public readonly bool $wasFirstLoad,
        public readonly array $entityCountsByType,
        public readonly array $removedOrphans = [],
    ) {}

    public function totalEntities(): int
    {
        return array_sum($this->entityCountsByType);
    }
}
