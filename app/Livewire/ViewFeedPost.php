<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReactsAndCommentsOnFeedPosts;
use App\Models\FeedPost;
use App\Models\Reaction;
use Illuminate\View\View;
use Livewire\Component;

class ViewFeedPost extends Component
{
    // TASK-1752 (§5.1 du CDC) — les deux boutons de la carte levaient
    // `MethodNotFoundException` : la vue inclut la MEME carte que la liste,
    // qui appelle `toggleReaction` et `addComment`, et ce composant ne les
    // avait pas. Le trait les apporte, a l'identique de `OrganizationFeed`.
    use ReactsAndCommentsOnFeedPosts;

    public FeedPost $feedPost;

    public function mount(FeedPost $feedPost): void
    {
        $org = currentOrganization();

        if (! $org || $feedPost->organization_id !== $org->id) {
            abort(404);
        }

        if ($feedPost->status !== FeedPost::STATUS_PUBLISHED || $feedPost->trashed()) {
            abort(404);
        }

        if (! auth()->user()?->can('view', $feedPost)) {
            abort(403);
        }

        $this->feedPost = $feedPost->load(['user', 'reactions.user', 'comments.user', 'loops']);
    }

    public function render(): View
    {
        // Relu a chaque rendu : une reaction ou un commentaire vient
        // peut-etre d'etre ecrit par le trait, et les relations chargees au
        // `mount()` sont alors perimees — la carte afficherait l'etat
        // d'avant le clic.
        $this->feedPost->load(['user', 'reactions.user', 'comments.user', 'loops']);

        $emojiMap = Reaction::emojiMap();

        return view('livewire.view-feed-post', [
            'post' => $this->feedPost,
            'emojiMap' => $emojiMap,
        ]);
    }
}
