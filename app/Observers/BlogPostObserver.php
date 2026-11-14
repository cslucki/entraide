<?php

namespace App\Observers;

use App\Models\BlogPost;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\LoopMessageService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class BlogPostObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private DossierArticleIndexingDispatcher $indexing,
        private LoopMessageService $loopMessages,
    ) {}

    public function updated(BlogPost $post): void
    {
        if (! $post->wasChanged(['content', 'status', 'published_at'])) {
            return;
        }

        $this->indexing->dispatchForBlogPost($post);

        // TASK-1826 — le SEUL endroit ou la transition brouillon -> publie se
        // constate pour TOUT Article, quel que soit le bouton qui l'a
        // declenchee : c'est pourquoi le declencheur vit ici plutot que
        // duplique dans chaque controleur de publication.
        //
        // Ne couvre PAS le cas « Article deja publie, partage ensuite dans
        // une Boucle » — aucun statut ne change alors, cette methode ne
        // tourne pas. Ce second cas est couvert aux points d'ATTACHE
        // eux-memes (`BlogPostLoopController::store()`,
        // `DossierArticleController::store()`), qui appellent la MEME methode
        // idempotente : les deux chemins ne peuvent jamais produire deux
        // annonces pour la meme paire (Boucle, Article).
        if ($post->wasChanged(['status', 'published_at']) && $post->statusIsPublished()) {
            $this->annoncerDansSesBoucles($post);
        }
    }

    private function annoncerDansSesBoucles(BlogPost $post): void
    {
        $auteur = $post->user;

        if ($auteur === null) {
            return;
        }

        foreach ($post->linkedLoops() as $loop) {
            try {
                $this->loopMessages->sendArticlePublishedMessage($loop, $auteur, $post);
            } catch (\Throwable $e) {
                Log::warning('TASK-1826 : annonce de publication impossible dans la Boucle', [
                    'blog_post_id' => $post->id,
                    'loop_id' => $loop->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }
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
