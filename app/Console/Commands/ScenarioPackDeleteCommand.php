<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * TASK-1240 — suppression bornee d'un scenario pack : retire uniquement les
 * entites que ce pack a creees dans cette Organization (contrat TASK-1239
 * S12). No-op si le pack n'est pas charge.
 */
class ScenarioPackDeleteCommand extends Command
{
    protected $signature = 'scenario-pack:delete {pack : pack_id} {organization : slug de l\'Organization cible} {--yes : Ignore la confirmation interactive}';

    protected $description = 'Supprime (borne) un scenario pack (TASK-1240) d\'une Organization';

    public function handle(ScenarioPackRemover $remover): int
    {
        $organization = Organization::query()->where('slug', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error("Organization introuvable pour le slug '{$this->argument('organization')}'.");

            return self::FAILURE;
        }

        $packId = $this->argument('pack');
        $this->warn("Ceci va retirer toutes les entites creees par le pack '{$packId}' dans '{$organization->slug}' (rien d'autre).");
        if (! $this->option('yes') && ! $this->confirm('Confirmer la suppression ?')) {
            $this->info('Annule.');

            return self::SUCCESS;
        }

        try {
            $remover->remove($packId, $organization);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pack '{$packId}' retire de '{$organization->slug}' (ou n'etait deja pas charge).");

        return self::SUCCESS;
    }
}
