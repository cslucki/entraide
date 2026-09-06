<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\ScenarioPackLoad;
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

        // TASK-1351 — lu AVANT le retrait : `remove()` supprime la ligne de
        // chargement, donc la provenance de l'Organization n'existera plus
        // apres. Absente ou false (les deux packs anterieurs, et tout
        // chargement d'avant cette colonne) : l'Organization survit, comme
        // toujours.
        $organizationCreatedByPack = (bool) ScenarioPackLoad::query()
            ->where('organization_id', $organization->id)
            ->where('pack_id', $packId)
            ->value('organization_created_by_pack');

        try {
            $remover->remove($packId, $organization);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pack '{$packId}' retire de '{$organization->slug}' (ou n'etait deja pas charge).");

        if ($organizationCreatedByPack) {
            // L'Organization n'a jamais ete une entite du registre : elle ne
            // peut pas etre purgee par le moteur. C'est ce drapeau, ecrit au
            // chargement, qui autorise — seul — le retour a l'etat ABSENT.
            $slug = $organization->slug;
            $organization->forceDelete();
            $this->info("Organization '{$slug}' supprimee : elle avait ete creee par ce chargement.");
        }

        return self::SUCCESS;
    }
}
