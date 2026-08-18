<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use Illuminate\Support\Facades\DB;

/**
 * Suppression bornee d'un scenario pack (TASK-1240), conforme au contrat
 * TASK-1239 S12 : retrait complet des entites que CE pack a creees dans
 * cette Organization, aucun impact sur le reste des donnees de
 * l'Organization, jamais une suppression globale non bornee.
 *
 * No-op si le pack n'est pas charge dans cette Organization : "retirer" ce
 * qui n'existe pas n'est pas une erreur (a la difference du reset).
 */
class ScenarioPackRemover
{
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
                ->get(['entity_model', 'entity_id']);

            foreach ($entities as $entity) {
                $modelClass = $entity->entity_model;
                if (class_exists($modelClass)) {
                    $modelClass::query()
                        ->whereKey($entity->entity_id)
                        ->where('organization_id', $organization->id)
                        ->delete();
                }
            }

            // Cascade DB : supprime aussi les ScenarioPackEntity de ce chargement.
            $load->delete();
        });
    }
}
