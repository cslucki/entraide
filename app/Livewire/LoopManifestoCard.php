<?php

namespace App\Livewire;

use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Services\LoopManifestoService;
use App\Support\Loops\LoopPermissionResolver;
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

        if (! $this->canPublish()) {
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

        if (! $this->canPublish()) {
            return;
        }

        app(LoopManifestoService::class)->unpublish($this->loop);
        $this->loop->refresh();
    }

    /** Link a Dossiers document. No copy is ever made — see the service. */
    public function attachSource(string $dossierFileId): void
    {
        $this->errorMessage = null;

        if (! $this->canManageSources()) {
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
        if (! $this->canManageSources()) {
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

    /** Reading the Manifesto inside the workspace, resolved centrally. */
    private function canRead(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(LoopPermissionResolver::class)->can($user, $this->loop, 'manifesto.view');
    }

    /**
     * Who may edit the Manifesto — now resolved centrally (CP5ter).
     *
     * Was an inline owner check. The rule is unchanged for owners, admins and
     * members; what it gains is the facilitator, and the ability for a type or
     * an Organization to vary it.
     */
    private function canManageDesignation(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(LoopPermissionResolver::class)->can($user, $this->loop, 'manifesto.update');
    }

    /**
     * Publishing is a separate capability: a facilitator edits the Manifesto
     * but does not publish it by default, and that default is configurable.
     */
    private function canPublish(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(LoopPermissionResolver::class)->can($user, $this->loop, 'manifesto.publish');
    }

    private function canManageSources(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(LoopPermissionResolver::class)->can($user, $this->loop, 'manifesto.manage_sources');
    }

    /**
     * Manifesto body, sanitised for display inside the card.
     *
     * The Blog editor already sanitises on save, but the starter content is
     * inserted directly by createManifesto(), and a card is not the place to
     * trust that: this is defence in depth. The allowlist is deliberately
     * narrower than the Blog one — no iframe, no div, no table in a side panel.
     */
    private function renderManifesto(?BlogPost $manifesto): string
    {
        if (! $manifesto) {
            return '';
        }

        $allowed = ['h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 'br', 'a', 'code', 'pre', 'blockquote'];

        // Whole blocks first: strip_tags removes the tags but keeps their text,
        // so a <script> would survive as visible "alert(1)" gibberish. Inert,
        // but it has no business being displayed.
        $html = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', '', (string) $manifesto->content);

        $html = strip_tags((string) $html, '<'.implode('><', $allowed).'>');

        $html = preg_replace('/<(\w+)\s[^>]*on\w+\s*=\s*["\'][^"\']*["\']/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*(?:javascript|data)\s*:\s*[^"\'>\s]+/i', '<$1', $html);

        return (string) $html;
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
            'manifestoHtml' => $this->renderManifesto($manifesto),
            'version' => $version,
            'canRead' => $canRead,
            'canManage' => $canManage,
            'canPublish' => $this->canPublish(),
            'canManageSources' => $this->canManageSources(),
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
