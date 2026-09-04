<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Loops\RootDocumentLocaleRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Remettre le texte SYSTEME des documents racines d'une Organization dans la
 * langue de cette Organization (TASK-1389).
 *
 * Bornee a une Organization, et l'argument est OBLIGATOIRE : une reparation de
 * donnees qui balaierait tout le parc par defaut serait exactement le genre de
 * commande qu'on lance une fois de trop. Elle ne touche ni les slugs, ni le
 * texte ecrit par des personnes — voir `RootDocumentLocaleRepairService`.
 */
class LoopsRepairRootDocumentLocale extends Command
{
    protected $signature = 'loops:repair-root-document-locale
                            {organization : Slug ou id de l\'Organization a reparer}
                            {--dry-run : Montrer ce qui serait fait, sans rien ecrire}';

    protected $description = 'Retraduit le texte systeme des documents racines dans la locale de l\'Organization. Ne touche ni les slugs ni le texte humain.';

    public function handle(RootDocumentLocaleRepairService $reparation): int
    {
        $cible = $this->argument('organization');

        // `organizations.id` est un UUID : lui comparer un slug fait lever
        // PostgreSQL (`invalid input syntax for type uuid`) avant meme que la
        // clause sur le slug soit evaluee. On ne tente donc l'id que si la
        // valeur en est un.
        $organization = Organization::query()
            ->where('slug', $cible)
            ->when(Str::isUuid($cible), fn ($requete) => $requete->orWhere('id', $cible))
            ->first();

        if (! $organization) {
            $this->error("Organization introuvable : {$cible}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s — locale %s. %s',
            $organization->name,
            $organization->locale,
            $dryRun ? 'Simulation, rien ne sera ecrit.' : 'Application.',
        ));
        $this->newLine();

        $rapport = $dryRun
            ? $reparation->preview($organization)
            : $reparation->repair($organization);

        if ($rapport === []) {
            $this->info('Aucun document racine a reparer : tout est deja dans la bonne langue.');

            return self::SUCCESS;
        }

        $this->table(
            ['Boucle', 'Champs', 'Titre avant', 'Titre apres'],
            array_map(fn (array $ligne) => [
                mb_substr($ligne['loop'], 0, 30),
                implode(', ', $ligne['champs']),
                mb_substr($ligne['avant'], 0, 40),
                mb_substr($ligne['apres'], 0, 40),
            ], $rapport),
        );

        $this->newLine();
        $this->info(sprintf('%d document(s) %s.', count($rapport), $dryRun ? 'seraient repares' : 'repares'));

        return self::SUCCESS;
    }
}
