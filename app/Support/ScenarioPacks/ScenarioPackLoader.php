<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Charge un scenario pack dans une Organization (TASK-1240), conforme au
 * contrat TASK-1239 : Organization-scoped strict, idempotent, garde-fou
 * allowlist prealable a toute ecriture.
 *
 * Idempotence : rejouer `load()` avec le meme (pack_id, organization) met a
 * jour la ligne `scenario_pack_loads` existante (pack_version, loaded_at) et
 * laisse `ScenarioPackEntityRegistrar::track()` retrouver/mettre a jour les
 * entites deja creees plutot que d'en produire une seconde copie — a
 * condition que l'implementation du pack utilise elle-meme une primitive
 * idempotente (`updateOrCreate` sur cle naturelle) pour ses propres entites,
 * comme `ArtSciLabScenarioSeeder` (TASK-1203).
 */
class ScenarioPackLoader
{
    public function load(ScenarioPackDefinition $pack, Organization $organization): ScenarioPackLoadResult
    {
        ScenarioPackOrganizationGuard::assertAllowed($organization);

        return DB::transaction(function () use ($pack, $organization) {
            // Verrou sur la ligne Organization : deux chargements concurrents
            // du meme pack sur la meme Organization sont serialises, comme
            // OrganizationAiDoctrine::activate() le fait pour la doctrine.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            $load = ScenarioPackLoad::query()->firstOrNew([
                'organization_id' => $organization->id,
                'pack_id' => $pack->packId(),
            ]);
            $wasFirstLoad = ! $load->exists;

            $load->pack_version = $pack->packVersion();
            $load->loaded_at = now();
            $load->save();

            $registrar = new ScenarioPackEntityRegistrar($load);
            $pack->apply($organization, $registrar);

            $counts = ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $load->id)
                ->selectRaw('entity_type, count(*) as aggregate')
                ->groupBy('entity_type')
                ->pluck('aggregate', 'entity_type')
                ->map(fn ($count) => (int) $count)
                ->all();

            return new ScenarioPackLoadResult($load, $wasFirstLoad, $counts);
        });
    }
}
