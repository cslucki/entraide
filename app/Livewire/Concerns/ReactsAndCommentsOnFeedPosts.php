<?php

namespace App\Livewire\Concerns;

use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Reaction;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmissionConflict;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * TASK-1752 — reagir et commenter, en UN exemplaire.
 *
 * ## Le defaut que cette extraction corrige (§5.1 du CDC, PROUVE)
 *
 * `ViewFeedPost` (`/flux/{id}`) rend **la meme carte** que la liste —
 * `livewire.partials.feed-post-card` — et cette carte porte
 * `wire:click="toggleReaction(...)"` et `wire:submit="addComment(...)"`.
 * Or le composant n'avait que `mount()` et `render()`. Les deux boutons
 * levaient donc :
 *
 *     Livewire\Exceptions\MethodNotFoundException :
 *     Public method [toggleReaction] not found on component
 *
 * Et **aucun test ne couvrait `ViewFeedPost`** — c'est pour cela que le defaut
 * a survecu.
 *
 * ## Pourquoi un trait et pas une copie
 *
 * Copier les trente lignes dans le second composant aurait marche aujourd'hui
 * et diverge demain : la page SuperAdmin de cette meme TASK voudra la meme
 * carte, ce qui ferait une TROISIEME copie. Une regle de moderation ajoutee a
 * l'une des trois ne serait pas appliquee aux autres, et rien ne le dirait.
 *
 * ## Ce que le trait ne change PAS
 *
 * Le corps des deux methodes est repris **a l'identique** de
 * `OrganizationFeed`. Ce n'est pas le moment de les ameliorer : une extraction
 * qui modifie en meme temps ne peut plus etre prouvee neutre par les tests
 * existants.
 *
 * Les gardes restent donc telles quelles, et elles sont deja bonnes : portee
 * d'Organization verifiee a la main (`FeedPost` n'a pas de scope global),
 * puis la policy — qui, pour `react` et `comment`, delegue a `view`.
 */
trait ReactsAndCommentsOnFeedPosts
{
    /** @var array<string, string> */
    public array $commentForms = [];

    public function toggleReaction(string $feedPostId, string $reactionType): void
    {
        $orgId = currentOrganization()?->id;
        $user = auth()->user();

        if (! $orgId || ! $user || ! in_array($reactionType, Reaction::REACTION_TYPES, true)) {
            return;
        }

        $post = FeedPost::findOrFail($feedPostId);

        if ($post->organization_id !== $orgId || ! $user->can('react', $post)) {
            return;
        }

        $existing = Reaction::where('user_id', $user->id)
            ->where('reactionable_id', $post->id)
            ->where('reactionable_type', FeedPost::class)
            ->first();

        if ($existing) {
            if ($existing->reaction_type === $reactionType) {
                $existing->delete();
            } else {
                $existing->update(['reaction_type' => $reactionType]);
            }
        } else {
            Reaction::create([
                'organization_id' => $orgId,
                'user_id' => $user->id,
                'reactionable_id' => $post->id,
                'reactionable_type' => FeedPost::class,
                'reaction_type' => $reactionType,
            ]);

            $this->prevenirApresReactionFlux($post, $user);
        }
    }

    public function addComment(string $feedPostId): void
    {
        $orgId = currentOrganization()?->id;
        $user = auth()->user();

        if (! $orgId || ! $user) {
            return;
        }

        $post = FeedPost::where('id', $feedPostId)
            ->where('organization_id', $orgId)
            ->first();

        if (! $post) {
            return;
        }

        if (! $user->can('comment', $post)) {
            return;
        }

        $this->validate([
            "commentForms.{$feedPostId}" => ['required', 'string', 'max:2000'],
        ]);

        $commentaire = FeedPostComment::create([
            'feed_post_id' => $post->id,
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'content' => $this->commentForms[$feedPostId],
            'is_approved' => true,
        ]);

        $this->commentForms[$feedPostId] = '';

        $this->prevenirApresCommentaireFlux($post, $commentaire, $user);
    }

    /**
     * TASK-1810 (demande Cyril, 12/11) — « on a toujours un emetteur ou
     * quelqu'un qui est evoque, donc c'est cette personne qu'il faut
     * notifier ». C'est `$post->user` pour TOUT item du Flux, automatique ou
     * manuel (mesure dans `FeedEventEmitter::emettre()` : `user_id` vaut
     * toujours l'acteur de l'evenement ; `CreateFeedPost` l'ecrit de meme
     * pour une annonce manuelle).
     *
     * Destinataire unique, meme doctrine que `EntraideComments::
     * prevenirLAuteur()` : commenter son propre item ne notifie personne,
     * un auteur disparu ou sorti du tenant ne recoit rien.
     */
    private function prevenirApresCommentaireFlux(FeedPost $post, FeedPostComment $commentaire, User $acteur): void
    {
        if ((string) $post->user_id === (string) $acteur->id) {
            return;
        }

        $destinataire = User::find($post->user_id);

        if ($destinataire === null
            || (string) $destinataire->organization_id !== (string) $post->organization_id) {
            return;
        }

        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: NotificationCatalogue::FEED_POST_COMMENT_CREATED,
                organization: (string) $post->organization_id,
                recipient: $destinataire,
                eventId: (string) Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'bouclepro:notification:'.NotificationCatalogue::FEED_POST_COMMENT_CREATED.':'.$commentaire->id,
                ),
                objectType: NotificationCatalogue::objectTypeFor(NotificationCatalogue::FEED_POST_COMMENT_CREATED),
                objectId: (string) $commentaire->id,
                actor: $acteur,
            );
        } catch (NotificationEmissionConflict) {
            // L'auteur a deja ete prevenu de CE commentaire. Un rejeu n'est
            // pas une anomalie.
        } catch (\Throwable $echec) {
            Log::warning('TASK-1810 notification de commentaire de Flux non emise', [
                'feed_post_id' => $post->id,
                'feed_post_comment_id' => $commentaire->id,
                'raison' => $echec->getMessage(),
            ]);
        }
    }

    /**
     * TASK-1810 — « si la personne fait juste une reaction, la notification
     * est juste dans l'application, pas besoin d'envoyer un email » : UNE
     * seule cle, sans canal EMAIL ni WEB_PUSH (voir
     * `NotificationCatalogue::FEED_POST_REACTION_CREATED`).
     *
     * ## L'identite de l'evenement est (item, personne), jamais la reaction
     *
     * Une reaction peut etre supprimee puis recreee (second clic du meme
     * type dans `toggleReaction()`) — une NOUVELLE ligne `Reaction`, avec un
     * nouvel identifiant, a chaque fois. Cler l'evenement sur
     * `reaction->id` aurait donc renotifie a chaque va-et-tiens. Cler sur
     * (item, personne) fait du FAIT « cette personne a reagi a cet item »
     * un evenement UNIQUE, notifie une seule fois — exactement l'esprit de
     * la lutte contre l'infobesite.
     */
    private function prevenirApresReactionFlux(FeedPost $post, User $acteur): void
    {
        if ((string) $post->user_id === (string) $acteur->id) {
            return;
        }

        $destinataire = User::find($post->user_id);

        if ($destinataire === null
            || (string) $destinataire->organization_id !== (string) $post->organization_id) {
            return;
        }

        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: NotificationCatalogue::FEED_POST_REACTION_CREATED,
                organization: (string) $post->organization_id,
                recipient: $destinataire,
                eventId: (string) Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'bouclepro:notification:'.NotificationCatalogue::FEED_POST_REACTION_CREATED.':'.$post->id.':'.$acteur->id,
                ),
                objectType: NotificationCatalogue::objectTypeFor(NotificationCatalogue::FEED_POST_REACTION_CREATED),
                objectId: (string) $post->id,
                actor: $acteur,
            );
        } catch (NotificationEmissionConflict) {
            // Cette personne a deja reagi (et donc deja notifie) a CET item.
            // Un va-et-vient (retrait puis nouvelle reaction) n'est pas une
            // anomalie.
        } catch (\Throwable $echec) {
            Log::warning('TASK-1810 notification de reaction de Flux non emise', [
                'feed_post_id' => $post->id,
                'user_id' => $acteur->id,
                'raison' => $echec->getMessage(),
            ]);
        }
    }
}
