<?php

namespace App\Services\Dossiers;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;

class DossierArticleIndexingDispatcher
{
    /**
     * TASK-1307 : queue DEDIEE de la reindexation explicite (commande
     * `dossiers:index-articles`), meme convention que
     * `DossierFileIndexingDispatcher::DEDICATED_QUEUE` (TASK-1268). Jamais
     * `default` : c'est la queue que le flux normal d'attache utilise deja
     * (aucun worker dedie ne l'ecoute forcement sur toutes les surfaces).
     */
    public const DEDICATED_QUEUE = 'dossier-files-indexing';

    public function dispatch(string $organizationId, string $dossierId, string $blogPostId, ?string $queue = null): void
    {
        $pending = IndexDossierArticleChunks::dispatch($organizationId, $dossierId, $blogPostId)->afterCommit();

        if ($queue !== null && $queue !== '') {
            $pending->onQueue($queue);
        }
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
