<?php

namespace App\Services\Dossiers;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;

class DossierArticleIndexingDispatcher
{
    /**
     * TASK-1307 : queue DEDIEE de l'indexation des Articles de Dossier.
     * Jamais `default`.
     *
     * TASK-1408 : elle etait la queue de la seule reindexation EXPLICITE
     * (commande `dossiers:index-articles`), et portait la meme valeur que
     * `DossierFileIndexingDispatcher::DEDICATED_QUEUE`. Elle devient (a) celle
     * de TOUTE indexation d'Article, (b) DISTINCTE de celle des fichiers.
     *
     * Pourquoi distincte : un worker peut desormais consommer l'indexation des
     * FICHIERS sans consommer celle des Articles. Les deux flux n'ont ni le
     * meme volume, ni la meme maturite, ni la meme urgence — les melanger
     * revenait a ne pouvoir mettre en service ni l'un ni l'autre separement.
     */
    public const DEDICATED_QUEUE = 'dossier-articles-indexing';

    public function dispatch(string $organizationId, string $dossierId, string $blogPostId, ?string $queue = null): void
    {
        $pending = IndexDossierArticleChunks::dispatch($organizationId, $dossierId, $blogPostId)->afterCommit();

        // TASK-1408 : `null` et `''` signifient « aucune queue explicite
        // demandee », jamais « la queue `default` ». Sans cette ligne, les 15
        // chemins producteurs qui n'en passent aucune (les deux Observers, les
        // deux controleurs, LoopRootDocumentService,
        // LoopAnswerCapitalizationService) alimentaient `default`.
        //
        // Le defaut est pose ICI, dans le corps, et NON comme valeur par
        // defaut du parametre. `dispatchForEntries()` retransmet son propre
        // `$queue` EXPLICITEMENT (plus bas), y compris quand il vaut null — or
        // une valeur par defaut de parametre ne s'applique qu'a un argument
        // OMIS. Contrairement au cas jumeau de TASK-1407, ce relais a ici un
        // appelant REEL : `AiValidationIndexArtSciLabCommand` appelle
        // `dispatchForEntries($entries)` sans queue. La forme signature aurait
        // donc laisse ce chemin sur `default`, en affichant le bon defaut.
        $pending->onQueue($queue !== null && $queue !== '' ? $queue : self::DEDICATED_QUEUE);
    }

    public function dispatchForBlogPost(BlogPost $post): int
    {
        if (! is_string($post->organization_id) || $post->organization_id === '') {
            return 0;
        }

        return $this->dispatchForEntries(
            DossierBlogPost::query()
                ->where('organization_id', $post->organization_id)
                ->where('blog_post_id', $post->id)
                ->get(['organization_id', 'dossier_id', 'blog_post_id'])
        );
    }

    public function dispatchForDossier(Dossier $dossier): int
    {
        if (! is_string($dossier->organization_id) || $dossier->organization_id === '') {
            return 0;
        }

        return $this->dispatchForEntries(
            DossierBlogPost::query()
                ->where('organization_id', $dossier->organization_id)
                ->where('dossier_id', $dossier->id)
                ->get(['organization_id', 'dossier_id', 'blog_post_id'])
        );
    }

    /**
     * @param  iterable<int, DossierBlogPost|array{organization_id: string, dossier_id: string, blog_post_id: string}>  $entries
     */
    public function dispatchForEntries(iterable $entries, ?string $queue = null): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            $this->dispatch(
                (string) data_get($entry, 'organization_id'),
                (string) data_get($entry, 'dossier_id'),
                (string) data_get($entry, 'blog_post_id'),
                $queue,
            );

            $count++;
        }

        return $count;
    }
}
