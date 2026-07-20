<?php

namespace App\Observers;

use App\Models\BlogPost;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class BlogPostObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private DossierArticleIndexingDispatcher $indexing) {}

    public function updated(BlogPost $post): void
    {
        if (! $post->wasChanged(['content', 'status', 'published_at'])) {
            return;
        }

        $this->indexing->dispatchForBlogPost($post);
    }

    public function deleted(BlogPost $post): void
    {
        if ($post->isForceDeleting()) {
            return;
        }

        $this->indexing->dispatchForBlogPost($post);
    }

    public function restored(BlogPost $post): void
    {
        $this->indexing->dispatchForBlogPost($post);
    }
}
