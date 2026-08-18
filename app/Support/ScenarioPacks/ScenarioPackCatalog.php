<?php

namespace App\Support\ScenarioPacks;

use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackNotFoundException;

/**
 * Resout un `pack_id` vers son implementation `ScenarioPackDefinition`
 * (TASK-1240), a partir de `config('scenario_packs.definitions')`.
 *
 * Volontairement minimal : la selection/previsualisation multi-pack est le
 * perimetre de l'UI Admin (TASK-1241). Ce catalogue ne fait qu'une chose,
 * necessaire aux commandes Artisan de CETTE tache : transformer un
 * identifiant en instance.
 */
class ScenarioPackCatalog
{
    public function get(string $packId): ScenarioPackDefinition
    {
        $definitions = config('scenario_packs.definitions', []);

        $class = $definitions[$packId] ?? null;

        if ($class === null || ! class_exists($class) || ! is_a($class, ScenarioPackDefinition::class, true)) {
            throw ScenarioPackNotFoundException::forId($packId);
        }

        return app($class);
    }
}
