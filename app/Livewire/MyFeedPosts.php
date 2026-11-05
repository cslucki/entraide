<?php

namespace App\Livewire;

use App\Models\FeedPost;
use App\Services\LoopMessageService;
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

    public function togglePin(string $feedPostId): void
    {
        $post = FeedPost::findOrFail($feedPostId);

        // TASK-1752 — on passe L'ANNONCE, pas la classe.
        //
        // Avec la classe, la policy ne voyait que l'acteur : elle validait son
        // appartenance et laissait passer une cible d'une AUTRE Organization.
        // `FeedPost` n'ayant pas de scope global, le `findOrFail` ci-dessus ne
        // borne rien non plus. Ses deux voisines `delete()` et `publishNow()`
        // passaient deja l'objet — c'est cette methode qui etait l'exception.
        if (! auth()->user() || ! auth()->user()->can('pin', $post)) {
            abort(403);
        }

        $post->pinned_at ? $post->update(['pinned_at' => null, 'pinned_by_id' => null]) : $post->update(['pinned_at' => now(), 'pinned_by_id' => auth()->id()]);
    }

    public function delete(string $feedPostId): void
    {
        $post = FeedPost::findOrFail($feedPostId);

        if (! auth()->user() || ! auth()->user()->can('delete', $post)) {
            abort(403);
        }

        $post->delete();
    }

    public function publishNow(string $feedPostId, LoopMessageService $loopMessageService): void
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

        if ($post->loops()->exists()) {
            $post->broadcastToAssociatedLoops($loopMessageService, auth()->user());
        }
    }
}
