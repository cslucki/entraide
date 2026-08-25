<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackNotLoadedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOwnershipUnknownException;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * TASK-1245 : la reapplication ne modifie JAMAIS l'ownership des lignes deja
 * inscrites (fixe a la premiere inscription, cf. registrar) ; un orphelin
 * n'est purge physiquement que s'il est `created` (via la meme primitive que
 * le remover), un orphelin `reused` est seulement desinscrit, un orphelin a
 * ownership inconnu fait refuser le reset AVANT toute suppression.
 */
class ScenarioPackResetter
{
    public function __construct(private readonly ScenarioPackEntityPurger $purger = new ScenarioPackEntityPurger) {}

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
                ->get();

            $registrar = new ScenarioPackEntityRegistrar($load);
            try {
                $pack->apply($organization, $registrar);
            } catch (Throwable $e) {
                // Meme raison que dans ScenarioPackLoader : le storage ne
                // suit pas le rollback de la transaction.
                $registrar->discardStoragePathsClaimedThisRun();

                throw $e;
            }

            $trackedThisRun = $registrar->trackedKeys();

            $orphans = $before->reject(
                fn (ScenarioPackEntity $entity) => isset($trackedThisRun[$entity->entity_type.'|'.$entity->internal_key])
            )->sortByDesc('sequence');

            $unknown = $orphans->filter(fn (ScenarioPackEntity $entity) => $entity->hasUnknownOwnership());
            if ($unknown->isNotEmpty()) {
                throw ScenarioPackOwnershipUnknownException::forLoad(
                    $pack->packId(),
                    $organization->slug,
                    'reset (retrait des orphelins)',
                    $unknown->countBy('entity_type')->all(),
                );
            }

            $removedOrphans = [];
            foreach ($orphans as $orphan) {
                if ($orphan->isOwnedByPack()) {
                    $this->purger->purge($orphan, $organization);
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
