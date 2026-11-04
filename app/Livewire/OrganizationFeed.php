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
            $pinned = FeedPost::forOrganization($orgId)
                ->published()
                ->pinned()
                ->with(['user', 'reactions.user', 'comments.user', 'loops'])
                ->latest('published_at')
                ->get();

            $paginator = FeedPost::forOrganization($orgId)
                ->published()
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
