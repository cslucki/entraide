<?php

namespace App\Livewire;

use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Services\LoopManifestoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class LoopManifestoCard extends Component
{
    public Loop $loop;

    public bool $choosing = false;

    public bool $pickingSource = false;

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

    /**
     * Publish the Manifesto — always an explicit human action (TASK-1079).
     * Never triggered by an edit, never automatic.
     */
    public function publish(): void
    {
        $this->errorMessage = null;

        if (! $this->canManageDesignation()) {
            return;
        }

        if (! app(LoopManifestoService::class)->publish($this->loop)) {
            $this->errorMessage = __('loops.manifesto_publish_impossible');
        }

        $this->loop->refresh();
    }

    /** Back to draft: leaves the public presentation immediately. */
    public function unpublish(): void
    {
        $this->errorMessage = null;

        if (! $this->canManageDesignation()) {
            return;
        }

        app(LoopManifestoService::class)->unpublish($this->loop);
        $this->loop->refresh();
    }

    /** Link a Dossiers document. No copy is ever made — see the service. */
    public function attachSource(string $dossierFileId): void
    {
        $this->errorMessage = null;

        if (! $this->canManageDesignation()) {
            return;
        }

        $outcome = app(LoopManifestoService::class)
            ->attachSource($this->loop, $dossierFileId, auth()->user());

        if (! in_array($outcome['result'], [
            LoopManifestoService::RESULT_ATTACHED,
            LoopManifestoService::RESULT_ALREADY_ATTACHED,
        ], true)) {
            $this->errorMessage = __('loops.manifesto_source_refused');
        }

        $this->pickingSource = false;
    }

    /** Unlinks only: the document stays in Dossiers, untouched. */
    public function detachSource(string $dossierFileId): void
    {
        if (! $this->canManageDesignation()) {
            return;
        }

        app(LoopManifestoService::class)->detachSource($this->loop, $dossierFileId);
    }

    public function togglePickingSource(): void
    {
        if (! $this->canManageDesignation()) {
            return;
        }

        $this->pickingSource = ! $this->pickingSource;
        $this->errorMessage = null;
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
            'sources' => $canRead ? app(LoopManifestoService::class)->sourcesFor($this->loop) : collect(),
            'candidateFiles' => ($canManage && $this->pickingSource)
                ? app(LoopManifestoService::class)->candidateFiles($this->loop, 50)
                : collect(),
            // Shown as a plain statement of what the outside world can see, so
            // publishing is never a leap of faith.
            'isPublic' => $this->loop->hasPublicManifesto(),
            'loopIsPrivate' => $this->loop->visibility === 'private',
        ]);
    }
}
