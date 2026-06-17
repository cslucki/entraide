<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Models\Reaction;
use Illuminate\View\View;
use Livewire\Component;

class ViewFeedPost extends Component
{
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
        $emojiMap = Reaction::emojiMap();

        return view('livewire.view-feed-post', [
            'post' => $this->feedPost,
            'emojiMap' => $emojiMap,
        ]);
    }
}
