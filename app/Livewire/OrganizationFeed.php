<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Reaction;
use Livewire\Component;
use Livewire\WithPagination;

class OrganizationFeed extends Component
{
    use WithPagination;

    public int $perPage = 15;

    public array $commentForms = [];

    public function loadMore(): void
    {
        $this->perPage += 15;
    }

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
                ->latest('pinned_at')
                ->get();

            $paginator = FeedPost::forOrganization($orgId)
                ->published()
                ->whereNull('pinned_at')
                ->with(['user', 'reactions.user', 'comments.user', 'loops'])
                ->latest()
                ->paginate($this->perPage);

            $items = $paginator;
            $hasMore = $paginator->hasMorePages();
            $emojiMap = Reaction::emojiMap();
        }

        return view('livewire.organization-feed', compact('pinned', 'items', 'hasMore', 'emojiMap'));
    }
}
