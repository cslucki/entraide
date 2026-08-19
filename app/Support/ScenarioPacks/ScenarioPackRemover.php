<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOwnershipUnknownException;
use Illuminate\Support\Facades\DB;

/**
 * Suppression bornee d'un scenario pack (TASK-1240), conforme au contrat
 * TASK-1239 S12 : retrait complet des entites que CE pack a creees dans
 * cette Organization, aucun impact sur le reste des donnees de
 * l'Organization, jamais une suppression globale non bornee.
 *
 * TASK-1245 — retrait PHYSIQUE selon l'ownership du registre :
 *  - `created` : purge physique via `ScenarioPackEntityPurger` (forceDelete
 *    borne, fichier storage compris pour DossierFile), dans l'ordre inverse
 *    d'inscription (enfants avant parents, FK-safe) ;
 *  - `reused`  : entite preexistante seulement referencee -> laissee
 *    intacte, seule sa ligne de registre disparait (cascade du load) ;
 *  - NULL      : ownership inconnu (chargement anterieur a T1245) -> refus
 *    explicite AVANT toute suppression, jamais une purge partielle suivie
 *    de la destruction du registre.
 * `ScenarioPackLoad` garde son cycle de vie d'origine : supprime
 * physiquement, ce qui cascade sur `scenario_pack_entities` (pas de journal
 * d'audit dans T1245).
 *
 * No-op si le pack n'est pas charge dans cette Organization : "retirer" ce
 * qui n'existe pas n'est pas une erreur (a la difference du reset).
 */
class ScenarioPackRemover
{
    public function __construct(private readonly ScenarioPackEntityPurger $purger = new ScenarioPackEntityPurger) {}

    public function remove(string $packId, Organization $organization): void
    {
        ScenarioPackOrganizationGuard::assertAllowed($organization);

        DB::transaction(function () use ($packId, $organization) {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $load = ScenarioPackLoad::query()
                ->where('organization_id', $organization->id)
                ->where('pack_id', $packId)
                ->first();

            if ($load === null) {
                return;
            }

            $entities = ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $load->id)
                ->orderByDesc('sequence')
                ->get();

            $unknown = $entities->filter(fn (ScenarioPackEntity $entity) => $entity->hasUnknownOwnership());
            if ($unknown->isNotEmpty()) {
                throw ScenarioPackOwnershipUnknownException::forLoad(
                    $packId,
                    $organization->slug,
                    'suppression',
                    $unknown->countBy('entity_type')->all(),
                );
            }

            foreach ($entities as $entity) {
                if ($entity->isOwnedByPack()) {
                    $this->purger->purge($entity, $organization);
                }
            }

            // Cascade DB : supprime aussi les ScenarioPackEntity de ce chargement.
            $load->delete();
        });
    }
}
