<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackNotLoadedException;
use Illuminate\Support\Facades\DB;

/**
 * Reset d'un scenario pack (TASK-1240), conforme au contrat TASK-1239 S11 :
 * "retour a l'etat obtenu immediatement apres un chargement propre", pas une
 * suppression totale.
 *
 * Mecanisme : reapplique le pack (comme un chargement idempotent), puis
 * retire les entites qui existaient dans le registre AVANT ce passage mais
 * que le passage n'a pas re-declarees — des orphelins issus d'une version
 * anterieure du pack. Le reset ne touche jamais une entite qui n'est pas
 * dans le registre de CE chargement.
 */
class ScenarioPackResetter
{
    public function reset(ScenarioPackDefinition $pack, Organization $organization): ScenarioPackLoadResult
    {
        ScenarioPackOrganizationGuard::assertAllowed($organization);

        return DB::transaction(function () use ($pack, $organization) {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $load = ScenarioPackLoad::query()
                ->where('organization_id', $organization->id)
                ->where('pack_id', $pack->packId())
                ->first();

            if ($load === null) {
                throw ScenarioPackNotLoadedException::forPack($pack->packId(), $organization->slug);
            }

            $before = ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $load->id)
                ->get(['id', 'entity_type', 'internal_key', 'entity_model', 'entity_id', 'sequence']);

            $registrar = new ScenarioPackEntityRegistrar($load);
            $pack->apply($organization, $registrar);

            $trackedThisRun = $registrar->trackedKeys();

            $orphans = $before->reject(
                fn (ScenarioPackEntity $entity) => isset($trackedThisRun[$entity->entity_type.'|'.$entity->internal_key])
            )->sortByDesc('sequence');

            $removedOrphans = [];
            foreach ($orphans as $orphan) {
                $modelClass = $orphan->entity_model;
                if (class_exists($modelClass)) {
                    $modelClass::query()
                        ->whereKey($orphan->entity_id)
                        ->where('organization_id', $organization->id)
                        ->delete();
                }
                $removedOrphans[] = $orphan->entity_type.'|'.$orphan->internal_key;
                $orphan->delete();
            }

            $load->pack_version = $pack->packVersion();
            $load->reset_at = now();
            $load->save();

            $counts = ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $load->id)
                ->selectRaw('entity_type, count(*) as aggregate')
                ->groupBy('entity_type')
                ->pluck('aggregate', 'entity_type')
                ->map(fn ($count) => (int) $count)
                ->all();

            return new ScenarioPackLoadResult($load, false, $counts, $removedOrphans);
        });
    }
}
