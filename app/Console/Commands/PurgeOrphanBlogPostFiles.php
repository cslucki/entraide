<?php

namespace App\Console\Commands;

use App\Models\BlogPostFile;
use Illuminate\Console\Command;

/**
 * TASK-1907 — supprime les fichiers d'article deposes puis jamais rattaches.
 *
 * Un PDF ou un audio depose dans l'editeur n'est rattache qu'a l'enregistrement
 * de l'article. S'il est retire avant, ou si l'article n'est jamais enregistre,
 * il reste stocke sans servir a rien. Apres 7 jours (le temps de finir un
 * brouillon), la ligne et le fichier sont supprimes.
 */
class PurgeOrphanBlogPostFiles extends Command
{
    public const DELAI_JOURS = 7;

    protected $signature = 'blog:purge-orphan-files';

    protected $description = 'Supprime les PDF et audios d\'articles jamais rattaches apres 7 jours.';

    public function handle(): int
    {
        $count = 0;

        BlogPostFile::query()
            ->withoutGlobalScopes()
            ->whereNull('blog_post_id')
            ->where('created_at', '<', now()->subDays(self::DELAI_JOURS))
            ->orderBy('created_at')
            ->chunkById(100, function ($files) use (&$count) {
                foreach ($files as $file) {
                    // Le modele supprime aussi le fichier sur le disque.
                    $file->delete();
                    $count++;
                }
            });

        $this->info($count.' fichier(s) orphelin(s) supprime(s).');

        return self::SUCCESS;
    }
}
