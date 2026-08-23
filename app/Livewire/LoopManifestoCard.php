<?php

namespace App\Livewire;

use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Services\LoopManifestoService;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class LoopManifestoCard extends Component
{
    public Loop $loop;

    public bool $choosing = false;

    public bool $pickingSource = false;

    public ?string $errorMessage = null;

    /** Live filter of the article picker. */
    public string $search = '';

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

        // Through the service, never inline: it is the only place that keeps
        // the Dossier, the designation and the legacy field consistent, and it
        // applies the type's template rather than a generic starter text.
        $post = app(LoopRootDocumentService::class)->ensureRootDocument($this->loop, auth()->user());

        $this->loop->refresh();

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

        $service = app(LoopRootDocumentService::class);

        // Eligibility is decided by the service, which scopes to the Loop's own
        // Organization and excludes articles that are already another Loop's
        // root document. Passing an id from outside that set changes nothing.
        $post = $service->eligibleArticles($this->loop, null, 500)
            ->firstWhere('id', $blogPostId);

        if (! $post) {
            $this->errorMessage = __('loops.manifesto_invalid_article');

            return;
        }

        $service->replace($this->loop, $post);

        $this->loop->refresh();
        $this->choosing = false;
        $this->search = '';
    }

    /**
     * Replacement is the only path — there is no "remove".
     *
     * A Loop always has a root document. Removing it would leave the card
     * empty and the Loop without its reference text; the previous document is
     * kept in the root Dossier as an ordinary article instead.
     */
    public function updatedSearch(): void
    {
        // Livewire re-renders on its own; the candidate list is rebuilt with
        // the new search term. Nothing to do beyond existing.
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
        return $this->redirect($this->editorUrl($post), navigate: false);
    }

    /**
     * L'editeur du manifeste, toujours sur la route org-scoped canonique.
     *
     * La route courte `blog.edit` resout l'Organization de la session : suivie
     * depuis une Boucle d'une autre org, elle repondait 404 (TASK-1279,
     * symptome A — meme famille que le 404 des join requests, TASK-1277, mais
     * `loopRoute()` est privee de LoopController et ne couvre pas ce composant).
     * L'org du lien est celle de la Boucle : c'est elle qui possede le post.
     */
    private function editorUrl(BlogPost $post): string
    {
        return route('organization.blog.edit', [
            'organization' => $this->loop->organization,
            'post' => $post,
        ]);
    }

    /**
     * Re-scope: the article must belong to this Organization AND be linked to this Loop.
     */
    /**
     * The Loop's root document.
     *
     * Read from the root Dossier, with the legacy designation as a transitional
     * fallback. The service keeps both in step, so this only matters for a Loop
     * that has not been backfilled yet.
     */
    private function rootDocument(): ?BlogPost
    {
        $dossier = Dossier::where('loop_id', $this->loop->id)->first();

        if ($dossier?->root_blog_post_id) {
            return BlogPost::with('user')->find($dossier->root_blog_post_id);
        }

        return $this->loop->manifesto()->with('user')->first();
    }

    /**
     * Articles that may become this Loop's root document.
     *
     * Deliberately NOT restricted to articles already linked to the Loop — that
     * was the old behaviour and it made the picker useless: you could only pick
     * something you had already attached. The scope is the Organization, minus
     * the current document and minus any article that is another Loop's root.
     */
    private function candidates(): Collection
    {
        $current = $this->rootDocument()?->id;

        return app(LoopRootDocumentService::class)
            ->eligibleArticles($this->loop, $this->search ?: null, 15)
            ->reject(fn ($p) => $p->id === $current)
            ->values();
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

        // Same allowlist as Loop::manifestoHtmlForAdmin(), h1 included.
        $allowed = ['h1', 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 'br', 'a', 'code', 'pre', 'blockquote'];

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

        // Source of truth: Loop -> root Dossier -> root_blog_post_id.
        // The legacy loops.manifesto_blog_post_id is still read as a fallback
        // while it exists, so a Loop not yet backfilled keeps working — but the
        // service keeps the two in step, so they never diverge.
        $manifesto = $canRead ? $this->rootDocument() : null;

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
            // The label comes from the registry, never from a condition on the
            // type: Manifeste for a project, Cadre du dialogue for a Dialogue
            // Loop, Programme for a Formation.
            'documentLabel' => app(LoopTypeRegistry::class)->rootDocumentLabel($this->loop->type),
            'editorUrl' => $manifesto ? $this->editorUrl($manifesto) : null,
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
