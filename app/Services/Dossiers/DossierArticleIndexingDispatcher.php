<?php

namespace App\Services\Dossiers;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;

class DossierArticleIndexingDispatcher
{
    public function dispatch(string $organizationId, string $dossierId, string $blogPostId): void
    {
        IndexDossierArticleChunks::dispatch($organizationId, $dossierId, $blogPostId)->afterCommit();
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
    public function dispatchForEntries(iterable $entries): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            $this->dispatch(
                (string) data_get($entry, 'organization_id'),
                (string) data_get($entry, 'dossier_id'),
                (string) data_get($entry, 'blog_post_id'),
            );

            $count++;
        }

        return $count;
    }
}
