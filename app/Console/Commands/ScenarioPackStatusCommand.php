<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use Illuminate\Console\Command;

/**
 * TASK-1240 — statut d'un scenario pack pour une Organization : charge ou
 * non, version, horodatages, decompte d'entites par type (contrat TASK-1239
 * S13 : preuves lisibles).
 */
class ScenarioPackStatusCommand extends Command
{
    protected $signature = 'scenario-pack:status {pack : pack_id} {organization : slug de l\'Organization cible}';

    protected $description = 'Affiche le statut d\'un scenario pack (TASK-1240) pour une Organization';

    public function handle(): int
    {
        $organization = Organization::query()->where('slug', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error("Organization introuvable pour le slug '{$this->argument('organization')}'.");

            return self::FAILURE;
        }

        $packId = $this->argument('pack');
        $load = ScenarioPackLoad::query()
            ->where('organization_id', $organization->id)
            ->where('pack_id', $packId)
            ->first();

        if ($load === null) {
            $this->line("Pack '{$packId}' : non charge dans '{$organization->slug}'.");

            return self::SUCCESS;
        }

        $counts = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->id)
            ->selectRaw('entity_type, count(*) as aggregate')
            ->groupBy('entity_type')
            ->pluck('aggregate', 'entity_type');

        $this->line("Pack         : {$load->pack_id} v{$load->pack_version}");
        $this->line("Organization : {$organization->slug} ({$organization->id})");
        $this->line("Charge le    : {$load->loaded_at}");
        $this->line('Dernier reset : '.($load->reset_at?->toDateTimeString() ?? 'jamais'));
        $this->line('Entites      : '.$counts->sum());
        foreach ($counts as $type => $count) {
            $this->line("  - {$type} : {$count}");
        }

        return self::SUCCESS;
    }
}
