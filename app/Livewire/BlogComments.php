<?php

namespace App\Livewire;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmissionConflict;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
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
        $commentaire = BlogComment::create([
            'blog_post_id' => $post->id,
            'user_id' => auth()->id(),
            'parent_id' => $parentId,
            'content' => $contenu,
            'is_approved' => true,
        ]);

        $this->prevenirLAuteur($post, $commentaire);

        return $commentaire;
    }

    /**
     * TASK-1697 — « quand un utilisateur fait un commentaire, il faut que
     * l'auteur de l'article recoive une notification » (Cyril).
     *
     * Canal : **dans l'application** seulement. Arbitrage de Cyril ; un
     * courriel par commentaire serait bruyant, et le catalogue permettra de
     * l'ajouter en une ligne.
     *
     * ## Trois raisons de ne RIEN emettre, et aucune n'est une erreur
     *
     * - on commente son propre article : se notifier soi-meme est du bruit ;
     * - l'auteur n'existe plus ;
     * - l'auteur a quitte l'Organization. Les invariants refusent un
     *   destinataire hors tenant, et le producteur doit resoudre
     *   l'appartenance AVANT d'appeler — c'est ce que dit le docblock de
     *   `NotificationEmitter`, pas une precaution de mon cru.
     *
     * ## Un echec d'emission ne defait pas le commentaire
     *
     * Le commentaire est ecrit et le restera. Un `NotificationEmissionConflict`
     * signifie que l'auteur a DEJA ete prevenu de ce fait — un rejeu, pas une
     * anomalie. Tout autre echec est journalise. Meme forme que
     * `LoopService::notifierDecisionDAdhesion()`.
     */
    private function prevenirLAuteur(BlogPost $post, BlogComment $commentaire): void
    {
        if ((string) $post->user_id === (string) auth()->id()) {
            return;
        }

        $auteur = User::find($post->user_id);

        if ($auteur === null || (string) $auteur->organization_id !== (string) $post->organization_id) {
            return;
        }

        $acteur = auth()->user();

        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: NotificationCatalogue::BLOG_COMMENT_CREATED,
                organization: (string) $post->organization_id,
                recipient: $auteur,
                eventId: $this->identifiantEvenement($commentaire),
                objectType: NotificationCatalogue::objectTypeFor(NotificationCatalogue::BLOG_COMMENT_CREATED),
                objectId: (string) $commentaire->id,
                actor: $acteur !== null && (string) $acteur->organization_id === (string) $post->organization_id ? $acteur : null,
            );
        } catch (NotificationEmissionConflict) {
            // Deja prevenu de ce commentaire. Un rejeu n'est pas une anomalie.
        } catch (\Throwable $echec) {
            Log::warning('TASK-1697 notification de commentaire non emise', [
                'blog_comment_id' => $commentaire->id,
                'blog_post_id' => $post->id,
                'raison' => $echec->getMessage(),
            ]);
        }
    }

    /**
     * L'identite de l'EVENEMENT, derivee de la CLE et du commentaire.
     *
     * MEME idiome que `LoopService::identifiantEvenementDecision()` : un
     * `uuid5` deterministe. La cle entre dans le nom, donc un second type de
     * notification sur le meme commentaire aurait un identifiant DIFFERENT —
     * ce que le contrat de `NotificationEmitter` exige explicitement.
     *
     * Deterministe a dessein : rejouer le meme producteur sur le meme fait ne
     * cree pas une seconde notification, il constate la premiere. Un
     * `Str::uuid()` frais aurait, lui, duplique a chaque rejeu.
     */
    private function identifiantEvenement(BlogComment $commentaire): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.NotificationCatalogue::BLOG_COMMENT_CREATED.':'.$commentaire->id,
        );
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
