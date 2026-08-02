<?php

namespace App\Livewire;

use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Loop;
use App\Models\LoopMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class LoopManifestoCard extends Component
{
    public Loop $loop;

    public bool $choosing = false;

    public ?string $errorMessage = null;

    /**
     * Create a fresh draft BlogPost, link it to this Loop, and designate it as the
     * primary Manifesto. Never publishes; the human keeps editing/publishing in the
     * existing editor (we redirect there).
     */
    public function createManifesto()
    {
        $this->errorMessage = null;

        if (! $this->canManageDesignation() || ! Gate::allows('create', BlogPost::class)) {
            return null;
        }

        $post = BlogPost::create([
            'user_id' => auth()->id(),
            'organization_id' => $this->loop->organization_id,
            'title' => __('loops.manifesto_default_title', ['loop' => $this->loop->name]),
            'summary' => null,
            'content' => __('loops.manifesto_starter_content'),
            'status' => 'draft',
        ]);

        $post->loops()->syncWithoutDetaching([$this->loop->id]);

        $this->loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

        return $this->redirectToEditor($post);
    }

    /**
     * Designate (or replace with) an existing article already linked to this Loop.
     */
    public function designate(string $blogPostId): void
    {
        $this->errorMessage = null;

        if (! $this->canManageDesignation()) {
            return;
        }

        $post = $this->resolveCandidate($blogPostId);
        if (! $post) {
            $this->errorMessage = __('loops.manifesto_invalid_article');

            return;
        }

        $this->loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();
        $this->choosing = false;
    }

    public function removeManifesto(): void
    {
        $this->errorMessage = null;

        if (! $this->canManageDesignation()) {
            return;
        }

        $this->loop->forceFill(['manifesto_blog_post_id' => null])->save();
        $this->choosing = false;
    }

    public function toggleChoosing(): void
    {
        if (! $this->canManageDesignation()) {
            return;
        }

        $this->choosing = ! $this->choosing;
    }

    private function redirectToEditor(BlogPost $post)
    {
        return $this->redirect(route('blog.edit', $post), navigate: false);
    }

    /**
     * Re-scope: the article must belong to this Organization AND be linked to this Loop.
     */
    private function resolveCandidate(string $blogPostId): ?BlogPost
    {
        if (! $this->canManageDesignation()) {
            return null;
        }

        return BlogPost::query()
            ->where('organization_id', $this->loop->organization_id)
            ->whereHas('loops', fn ($q) => $q->whereKey($this->loop->id))
            ->find($blogPostId);
    }

    private function candidates(): Collection
    {
        return BlogPost::query()
            ->where('organization_id', $this->loop->organization_id)
            ->whereHas('loops', fn ($q) => $q->whereKey($this->loop->id))
            ->when($this->loop->manifesto_blog_post_id, fn ($q) => $q->whereKeyNot($this->loop->manifesto_blog_post_id))
            ->latest('updated_at')
            ->limit(15)
            ->get(['id', 'title', 'status']);
    }

    private function activeMembership(): ?LoopMember
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return null;
        }
        if ($this->loop->organization_id !== $user->organization_id && ! $user->is_admin) {
            return null;
        }

        return LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    private function canRead(): bool
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return false;
        }

        return $user->is_admin || $this->activeMembership() !== null;
    }

    /** Designation is reserved to owner / moderator / super-admin. */
    private function canManageDesignation(): bool
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }

        $membership = $this->activeMembership();

        return $membership !== null && in_array($membership->role, ['owner', 'moderator'], true);
    }

    public function placeholder()
    {
        return view('livewire.loop-manifesto-card-placeholder');
    }

    public function render()
    {
        $canRead = $this->canRead();
        $canManage = $this->canManageDesignation();

        $manifesto = $canRead ? $this->loop->manifesto()->with('user')->first() : null;

        $version = $manifesto
            ? (BlogSnapshot::where('blog_post_id', $manifesto->id)->count() ?: 1)
            : null;

        return view('livewire.loop-manifesto-card', [
            'manifesto' => $manifesto,
            'version' => $version,
            'canRead' => $canRead,
            'canManage' => $canManage,
            'canCreate' => $canManage && Gate::allows('create', BlogPost::class),
            'candidates' => ($canManage && $this->choosing) ? $this->candidates() : collect(),
            'editorUrl' => $manifesto ? route('blog.edit', $manifesto) : null,
        ]);
    }
}
