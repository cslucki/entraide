<?php

namespace App\Console\Commands;

use App\Jobs\ExtractBlogPostFileText;
use App\Models\BlogPostFile;
use Illuminate\Console\Command;

/**
 * TASK-1907 — extrait, par petits lots, le texte des PDF d'articles en attente.
 *
 * Planifie plutot que lance au depot : en PROD la connexion de file par defaut
 * est `sync` et la file `default` n'est jamais consommee. Un job au depot
 * tournerait donc DANS la requete d'envoi (jusqu'a 20 Mo a analyser). Ici,
 * l'extraction se fait toutes les 5 minutes, hors de toute requete, quelle que
 * soit la configuration des files.
 */
class ExtractBlogPostFileTexts extends Command
{
    protected $signature = 'blog:extract-file-texts {--limit=10 : Nombre maximal de PDF par passage}';

    protected $description = 'Extrait le texte des PDF d\'articles pas encore traites (recherche, IA).';

    public function handle(): int
    {
        $ids = BlogPostFile::query()
            ->withoutGlobalScopes()
            ->where('kind', BlogPostFile::KIND_PDF)
            ->whereNull('extracted_at')
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        foreach ($ids as $id) {
            dispatch_sync(new ExtractBlogPostFileText($id));
        }

        $this->info($ids->count().' PDF traite(s).');

        return self::SUCCESS;
    }
}
