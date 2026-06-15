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
}
