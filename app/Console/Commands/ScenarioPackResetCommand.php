<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * TASK-1240 — reset d'un scenario pack : reapplique le pack et retire les
 * orphelins d'une version anterieure (contrat TASK-1239 S11).
 */
class ScenarioPackResetCommand extends Command
{
    protected $signature = 'scenario-pack:reset {pack : pack_id} {organization : slug de l\'Organization cible} {--yes : Ignore la confirmation interactive}';

    protected $description = 'Reset un scenario pack (TASK-1240) : retour a l\'etat d\'un chargement propre';

    public function handle(ScenarioPackCatalog $catalog, ScenarioPackResetter $resetter): int
    {
        $organization = Organization::query()->where('slug', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error("Organization introuvable pour le slug '{$this->argument('organization')}'.");

            return self::FAILURE;
        }

        $this->warn("Ceci va reappliquer le pack '{$this->argument('pack')}' et retirer les entites orphelines dans '{$organization->slug}'.");
        if (! $this->option('yes') && ! $this->confirm('Confirmer le reset ?')) {
            $this->info('Annule.');

            return self::SUCCESS;
        }

        try {
            $pack = $catalog->get($this->argument('pack'));
            $result = $resetter->reset($pack, $organization);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Reset termine.');
        $this->line("Entites restantes : {$result->totalEntities()}");
        $this->line('Orphelins retires : '.count($result->removedOrphans));
        foreach ($result->removedOrphans as $orphan) {
            $this->line("  - {$orphan}");
        }

        return self::SUCCESS;
    }
}
