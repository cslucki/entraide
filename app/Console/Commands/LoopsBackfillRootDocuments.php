<?php

namespace App\Console\Commands;

use App\Models\Dossier;
use App\Models\Loop;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * Give every existing Loop its root Dossier and root document.
 *
 * Deliberately simple: the installed base is small, so this is a loop over
 * Loops calling the same service the creation flow uses — not a migration
 * engine. Idempotent, so it can be run again safely, and `--dry-run` reports
 * what it would do without writing anything.
 *
 * Never destructive: an existing designated article is adopted, moved into the
 * root Dossier and kept with its content, its history and its author. Nothing
 * is deleted, and no status is rewritten beyond the audience and the Blog flag
 * that make it a Loop document.
 *
 * Langue (TASK-1388) : le document cree porte un titre et des sections
 * PERSISTES depuis `__()`. Cette commande balaie plusieurs Organizations en
 * une passe, depuis un shell dont la locale n'a rien a voir avec elles — elle
 * ecrit donc chaque document sous la locale de SON Organization.
 */
class LoopsBackfillRootDocuments extends Command
{
    use Localizable;

    protected $signature = 'loops:backfill-root-documents
                            {--dry-run : Report what would change, write nothing}
                            {--organization= : Restrict to one Organization id}';

    protected $description = 'Create the root Dossier and root document of every Loop that lacks them';

    public function handle(LoopRootDocumentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $loops = Loop::query()
            ->when($this->option('organization'), fn ($q, $id) => $q->where('organization_id', $id))
            // L'Organization est lue pour sa locale a chaque ecriture : la
            // charger ici evite une requete par Boucle.
            ->with('organization')
            ->orderBy('created_at')
            ->get();

        $withDossier = Dossier::whereNotNull('loop_id')->pluck('loop_id')->all();

        $report = ['analysees' => $loops->count(), 'dossiers' => 0, 'documents' => 0, 'reutilises' => 0, 'lies' => 0, 'inchangees' => 0, 'erreurs' => 0];

        $this->line($dryRun ? 'Simulation — rien ne sera ecrit.' : 'Application.');
        $this->newLine();

        foreach ($loops as $loop) {
            $hasDossier = in_array($loop->id, $withDossier, true);
            $dossier = $hasDossier ? Dossier::where('loop_id', $loop->id)->first() : null;

            if ($dossier && $dossier->root_blog_post_id) {
                // Le document existe — mais son lien a la Boucle peut manquer :
                // designate() ne l'ecrivait pas avant TASK-1121, et l'editeur
                // montrait une section « Boucle » vide sur un Manifeste ne pour
                // elle. Le service le pose au passage (syncWithoutDetaching) ;
                // sauter ces Boucles aurait laisse la commande incapable de
                // reparer le parc qu'elle a elle-meme construit.
                $lienManquant = ! DB::table('blog_post_loop')
                    ->where('loop_id', $loop->id)
                    ->where('blog_post_id', $dossier->root_blog_post_id)
                    ->exists();

                if (! $lienManquant) {
                    $report['inchangees']++;

                    continue;
                }

                if ($dryRun) {
                    $report['lies']++;
                    $this->line(sprintf('  %-30s relierait son document a la Boucle', mb_substr($loop->name, 0, 30)));

                    continue;
                }

                try {
                    $this->withLocale($loop->organization?->locale, fn () => $service->ensureRootDocument($loop));
                    $report['lies']++;
                } catch (\Throwable $e) {
                    $report['erreurs']++;
                    $this->error(sprintf('  %-30s %s', mb_substr($loop->name, 0, 30), $e->getMessage()));
                }

                continue;
            }

            $reusing = (bool) $loop->manifesto_blog_post_id;

            if ($dryRun) {
                $hasDossier ? null : $report['dossiers']++;
                $reusing ? $report['reutilises']++ : $report['documents']++;
                $this->line(sprintf('  %-30s %s', mb_substr($loop->name, 0, 30), $reusing ? 'adopterait son article existant' : 'creerait un document'));

                continue;
            }

            try {
                $this->withLocale($loop->organization?->locale, fn () => $service->ensureRootDocument($loop));
                $hasDossier ? null : $report['dossiers']++;
                $reusing ? $report['reutilises']++ : $report['documents']++;
            } catch (\Throwable $e) {
                $report['erreurs']++;
                $this->error(sprintf('  %-30s %s', mb_substr($loop->name, 0, 30), $e->getMessage()));
            }
        }

        $this->newLine();
        $this->table(
            ['Boucles analysees', 'Dossiers crees', 'Documents crees', 'Articles adoptes', 'Liens poses', 'Deja en place', 'Erreurs'],
            [[$report['analysees'], $report['dossiers'], $report['documents'], $report['reutilises'], $report['lies'], $report['inchangees'], $report['erreurs']]],
        );

        return $report['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
