<?php

namespace App\Livewire\Concerns;

use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Reaction;

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

        FeedPostComment::create([
            'feed_post_id' => $post->id,
            'organization_id' => $orgId,
            'user_id' => $user->id,
            'content' => $this->commentForms[$feedPostId],
            'is_approved' => true,
        ]);

        $this->commentForms[$feedPostId] = '';
    }
}
