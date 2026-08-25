<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * TASK-1240 — charge un scenario pack dans une Organization. Idempotent :
 * rejouer sur le meme (pack, Organization) ne duplique rien.
 */
class ScenarioPackLoadCommand extends Command
{
    protected $signature = 'scenario-pack:load {pack : pack_id enregistre dans config(scenario_packs.definitions)} {organization : slug de l\'Organization cible}';

    protected $description = 'Charge un scenario pack (TASK-1240) dans une Organization qualifiee demonstration/dogfooding';

    public function handle(ScenarioPackCatalog $catalog, ScenarioPackLoader $loader): int
    {
        $organization = Organization::query()->where('slug', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error("Organization introuvable pour le slug '{$this->argument('organization')}'.");

            return self::FAILURE;
        }

        try {
            $pack = $catalog->get($this->argument('pack'));
            $result = $loader->load($pack, $organization);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result->wasFirstLoad ? 'Premier chargement.' : 'Rechargement idempotent (aucune duplication).');
        $this->line("Pack       : {$pack->packId()} v{$pack->packVersion()}");
        $this->line("Organization : {$organization->slug} ({$organization->id})");
        $this->line("Entites    : {$result->totalEntities()}");
        foreach ($result->entityCountsByType as $type => $count) {
            $this->line("  - {$type} : {$count}");
        }

        return self::SUCCESS;
    }
}
