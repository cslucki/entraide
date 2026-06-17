<?php

namespace App\Livewire;

use App\Models\FeedPost;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class MyFeedPosts extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public function render(): View
    {
        $organization = currentOrganization();

        abort_if($organization === null, 404);

        $posts = FeedPost::where('organization_id', $organization->id)
            ->where('user_id', auth()->id())
            ->latest()
            ->paginate(15);

        return view('livewire.my-feed-posts', [
            'posts' => $posts,
            'canCreate' => auth()->user()->can('create', FeedPost::class),
        ]);
    }

    public function delete(string $feedPostId): void
    {
        $post = FeedPost::findOrFail($feedPostId);

        if (! auth()->user() || ! auth()->user()->can('delete', $post)) {
            abort(403);
        }

        $post->delete();
    }

    public function publishNow(string $feedPostId): void
    {
        $post = FeedPost::findOrFail($feedPostId);

        if (! auth()->user() || ! auth()->user()->can('update', $post)) {
            abort(403);
        }

        abort_unless($post->status === FeedPost::STATUS_SCHEDULED, 422);

        $post->update([
            'status' => FeedPost::STATUS_PUBLISHED,
            'published_at' => now(),
            'scheduled_at' => null,
        ]);
    }
}
