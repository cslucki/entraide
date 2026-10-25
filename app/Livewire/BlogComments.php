<?php

namespace App\Livewire;

use App\Models\BlogComment;
use App\Models\BlogPost;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * TASK-1697 — les commentaires d'un article deviennent un composant Livewire.
 *
 * ## Pourquoi un composant, et pas une retouche de la vue
 *
 * Cyril a signale quatre choses qui n'en font qu'une :
 *
 * - « quand on fait un commentaire la page se rafraichit et revient au debut
 *   de l'article » — un POST plein cadre recharge tout ;
 * - « quand X ajoute un commentaire, Y doit rafraichir la page pour le voir »
 *   — il faut `wire:poll`, donc un composant ;
 * - « on voit les reponses aux commentaires que si on clique sur le picto » —
 *   tout le bloc vivait derriere `x-show="showComments"` ;
 * - « sur un commentaire, on ne voit pas de bouton Repondre » — il etait DANS
 *   ce bloc masque.
 *
 * Une seule conversion repond aux quatre. `wire:poll` est l'idiome deja en
 * place ici (`loop-chat` 3 s, `message-thread` 3000 ms) ; `BROADCAST_CONNECTION`
 * vaut `log`, il n'y a pas de websocket a solliciter.
 *
 * ## Les gardes RECOPIENT celles du controleur, elles n'en inventent aucune
 *
 * `BlogCommentController` reste en place et inchange : c'est encore lui que
 * sollicite un navigateur sans JavaScript. Ses regles sont donc reproduites a
 * l'identique — frontiere d'Organization, `max:2000`, parent appartenant a
 * l'article, suppression reservee a l'auteur ou a un administrateur. Deux
 * autorites qui divergeraient vaudraient moins qu'une.
 *
 * ## `postId` est verrouille
 *
 * `#[Locked]` : sans lui, le client pourrait reecrire la propriete et
 * commenter l'article d'une autre Organization avec un composant monte sur le
 * sien.
 */
class BlogComments extends Component
{
    #[Locked]
    public string $postId;

    public string $content = '';

    /** Identifiant du commentaire auquel on repond, ou `null`. */
    public ?string $replyTo = null;

    public string $replyContent = '';

    public function mount(BlogPost $post): void
    {
        $this->postId = (string) $post->id;
    }

    /**
     * L'article, re-resolu et re-garde a CHAQUE appel.
     *
     * Une requete Livewire est une requete HTTP a part entiere : elle ne
     * rejoue pas le `abort(404)` de `BlogController::show()`. La frontiere de
     * tenant doit donc etre refaite ici, sur chaque geste.
     */
    private function post(): BlogPost
    {
        $organization = currentOrganization();
        $post = BlogPost::find($this->postId);

        abort_if(! $organization || ! $post || $post->organization_id !== $organization->id, 404);

        return $post;
    }

    public function addComment(): void
    {
        abort_unless(auth()->check(), 403);

        $this->validate(['content' => 'required|string|max:2000']);

        $post = $this->post();

        $this->creer($post, $this->content, null);

        $this->content = '';
        $this->dispatch('comment-added');
    }

    public function startReply(string $commentId): void
    {
        abort_unless(auth()->check(), 403);

        // On ne propose de repondre qu'a un commentaire DE CET ARTICLE, et de
        // premier niveau : la base autorise la profondeur, le produit n'en
        // affiche qu'une (reference Twitter, arbitrage de Cyril).
        $parent = BlogComment::where('id', $commentId)
            ->where('blog_post_id', $this->post()->id)
            ->whereNull('parent_id')
            ->first();

        abort_if($parent === null, 404);

        $this->replyTo = (string) $parent->id;
        $this->replyContent = '';
    }

    public function cancelReply(): void
    {
        $this->replyTo = null;
        $this->replyContent = '';
    }

    public function addReply(): void
    {
        abort_unless(auth()->check(), 403);
        abort_if($this->replyTo === null, 404);

        $this->validate(['replyContent' => 'required|string|max:2000']);

        $post = $this->post();

        $parent = BlogComment::where('id', $this->replyTo)
            ->where('blog_post_id', $post->id)
            ->whereNull('parent_id')
            ->first();

        abort_if($parent === null, 404);

        $this->creer($post, $this->replyContent, (string) $parent->id);

        $this->cancelReply();
        $this->dispatch('comment-added');
    }

    public function deleteComment(string $commentId): void
    {
        abort_unless(auth()->check(), 403);

        $post = $this->post();

        $comment = BlogComment::where('id', $commentId)
            ->where('blog_post_id', $post->id)
            ->first();

        abort_if($comment === null, 404);

        // MEME regle que `BlogCommentController::destroy()`.
        abort_unless(auth()->id() === $comment->user_id || auth()->user()->is_admin, 403);

        $comment->delete();

        $this->dispatch('comment-removed');
    }

    private function creer(BlogPost $post, string $contenu, ?string $parentId): BlogComment
    {
        return BlogComment::create([
            'blog_post_id' => $post->id,
            'user_id' => auth()->id(),
            'parent_id' => $parentId,
            'content' => $contenu,
            'is_approved' => true,
        ]);
    }

    /**
     * @return Collection<int, BlogComment>
     */
    private function fil(BlogPost $post): Collection
    {
        return BlogComment::query()
            ->where('blog_post_id', $post->id)
            ->whereNull('parent_id')
            ->where('is_approved', true)
            ->with([
                'user',
                // Les reponses en ordre CHRONOLOGIQUE : un fil se lit du plus
                // ancien au plus recent. La relation `replies()` du modele est
                // en `latest()` et sert ailleurs — on ordonne ici plutot que
                // de la retourner pour tout le monde.
                'replies' => fn ($q) => $q->where('is_approved', true)->oldest()->with('user'),
            ])
            ->latest()
            ->get();
    }

    public function render()
    {
        $post = $this->post();
        $commentaires = $this->fil($post);

        return view('livewire.blog-comments', [
            'post' => $post,
            'commentaires' => $commentaires,
            'total' => $commentaires->count() + $commentaires->sum(fn ($c) => $c->replies->count()),
        ]);
    }
}
