<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReactsAndCommentsOnFeedPosts;
use App\Models\FeedPost;
use App\Models\Reaction;
use Livewire\Component;
use Livewire\WithPagination;

class OrganizationFeed extends Component
{
    use ReactsAndCommentsOnFeedPosts;
    use WithPagination;

    public int $perPage = 15;

    public function loadMore(): void
    {
        $this->perPage += 15;
    }

    public function render()
    {
        $orgId = currentOrganization()?->id;

        $pinned = collect();
        $items = collect();
        $hasMore = false;
        $emojiMap = [];

        if ($orgId && auth()->user()?->can('viewAny', FeedPost::class)) {
            // TASK-1752 — le filtre d'audience, sur LES DEUX requetes.
            //
            // L'oublier sur l'une des deux suffirait a faire fuir une ligne
            // restreinte : une annonce epinglee est lue par la premiere, tout
            // le reste par la seconde.
            $pinned = FeedPost::forOrganization($orgId)
                ->published()
                ->visibleTo(auth()->user())
                ->pinned()
                ->with(['user', 'reactions.user', 'comments.user', 'loops'])
                ->latest('published_at')
                ->get();

            $paginator = FeedPost::forOrganization($orgId)
                ->published()
                ->visibleTo(auth()->user())
                ->whereNull('pinned_at')
                ->with(['user', 'reactions.user', 'comments.user', 'loops'])
                ->latest('published_at')
                ->paginate($this->perPage);

            $items = $paginator;
            $hasMore = $paginator->hasMorePages();
            $emojiMap = Reaction::emojiMap();
        }

        return view('livewire.organization-feed', compact('pinned', 'items', 'hasMore', 'emojiMap'));
    }
}
