<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopGovernanceService;
use App\Services\LoopManifestoService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminLoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
    ) {}

    public function index(Request $request): View
    {
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        // Everything the list shows is counted in the query rather than walked
        // per row: members, invitations (total and pending) and cards. `cards`
        // is eager-loaded once for the badges — 25 rows, no N+1.
        $query = Loop::with(['creator:id,name,email', 'organization:id,name,slug', 'cards'])
            ->withCount([
                'activeMembers',
                'invitations',
                'invitations as pending_invitations_count' => fn ($q) => $q->where('status', LoopInvitation::STATUS_PENDING),
                'cards as enabled_cards_count' => fn ($q) => $q->where('enabled', true),
            ])
            ->latest();

        if ($selectedOrganizationId !== 'all') {
            $query->where('organization_id', $selectedOrganizationId);
        }

        $loops = $query->paginate(25)->withQueryString();

        $loops->load(['messages' => fn ($q) => $q->latest()->limit(1)]);

        return view('admin.loops.index', [
            'loops' => $loops,
            'organizations' => $organizations,
            'selectedOrganizationId' => $selectedOrganizationId,
            // Only what may be chosen. A Loop still on a withdrawn type keeps
            // it in its own selector — see selectableFor() in the view.
            'loopTypes' => app(LoopTypeRegistry::class)->available(),
        ]);
    }

    /**
     * Change a Loop's type and apply the new preset.
     *
     * Additive only: missing cards are added, nothing is ever removed and no
     * content is touched. The admin is told exactly what was added.
     */
    public function updateType(Request $request, Loop $loop): RedirectResponse
    {
        $registry = app(LoopTypeRegistry::class);

        $type = (string) $request->input('type');

        if (! $registry->exists($type)) {
            return back()->with('error', __('loops.type_invalid'));
        }

        // A type withdrawn from the choices cannot be assigned. Keeping the one
        // the Loop already carries is not an assignment, so it stays allowed —
        // otherwise saving anything else on the form would fail.
        if (! $registry->isAvailable($type) && $registry->resolve($loop->type) !== $type) {
            return back()->with('error', __('loops.type_unavailable'));
        }

        $loop->update(['type' => $type]);
        $added = $registry->applyPreset($loop->fresh());

        return back()->with('success', $added === []
            ? __('loops.type_changed_no_card')
            : __('loops.type_changed', [
                'cards' => collect($added)->map(fn ($k) => $registry->cardLabel($k))->implode(', '),
            ]));
    }

    /**
     * Turn one card on or off for one Loop.
     *
     * The permission is `loops.manage_cards`, declared since TASK-1079 and
     * until now without a single consumer. Every write goes through
     * LoopCardCompositionService: nothing here touches LoopCard directly.
     */
    public function updateCards(Request $request, Loop $loop): RedirectResponse
    {
        // Tenant-scoped even for a super-admin: the write always resolves
        // through the Loop's own Organization.
        $this->assertOrgAccess($loop);
        abort_unless(app(LoopPermissionResolver::class)->can($request->user(), $loop, 'loops.manage_cards'), 403);

        $data = $request->validate([
            'card_key' => 'required|string',
            'enabled' => 'required|boolean',
        ]);

        $service = app(LoopCardCompositionService::class);

        try {
            $data['enabled']
                ? $service->enable($loop, $data['card_key'])
                : $service->disable($loop, $data['card_key']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __($data['enabled'] ? 'loops.cards_enabled' : 'loops.cards_disabled'));
    }

    public function archive(Loop $loop): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        if ($loop->isArchived()) {
            return redirect()->route('admin.loops.edit', $loop)
                ->with('error', 'Cette boucle est déjà archivée.');
        }

        $this->loopService->archiveLoop($loop);

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Boucle archivée.');
    }

    public function restore(Loop $loop): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        if ($loop->isActive()) {
            return redirect()->route('admin.loops.edit', $loop)
                ->with('error', 'Cette boucle est déjà active.');
        }

        $this->loopService->restoreLoop($loop);

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Boucle réactivée.');
    }

    private function isSuperAdmin(): bool
    {
        return auth()->user()?->is_admin ?? false;
    }

    private function adminOrganizations(): Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    private function selectedAdminOrganizationId(Request $request): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        if ($this->isSuperAdmin()) {
            return 'all';
        }

        return (string) (auth()->user()?->organization_id
            ?? DefaultOrganizationResolver::resolve()?->getKey()
            ?? 'all');
    }

    public function create(): View
    {
        $user = auth()->user();

        if ($this->isSuperAdmin()) {
            $organizations = Organization::orderBy('name')->get(['id', 'name']);

            $users = User::assignable()
                ->with('organization:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'organization_id']);

            return view('admin.loops.create', [
                'users' => $users,
                'organizations' => $organizations,
                'loopTypes' => app(LoopTypeRegistry::class)->available(),
            ]);
        }

        $orgId = $user->organization_id;

        if (! $orgId) {
            abort(404);
        }

        $users = User::assignable()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.loops.create', [
            'users' => $users,
            'loopTypes' => app(LoopTypeRegistry::class)->available(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = auth()->user();

        if ($this->isSuperAdmin()) {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:5000',
                'visibility' => 'required|in:public,private',
                'owner_id' => [
                    'required',
                    Rule::exists('users', 'id')->whereNull('banned_at'),
                ],
                'organization_id' => 'required|exists:organizations,id',
                'type' => ['nullable', Rule::in(app(LoopTypeRegistry::class)->availableKeys())],
            ]);

            $owner = User::assignable()->findOrFail($data['owner_id']);

            if ($owner->organization_id !== $data['organization_id']) {
                abort(403, __('admin.owner_must_belong_to_org'));
            }

            $loop = $this->loopService->createLoopForOrg(
                $owner,
                $data['organization_id'],
                $data['name'],
                $data['description'] ?? null,
                $data['visibility'],
                null,
                Loop::ACCESS_REQUEST,
                $data['type'] ?? null,
            );

            return redirect()->route('admin.loops.edit', $loop)
                ->with('success', 'Boucle créée avec succès.');
        }

        $orgId = $user->organization_id;

        if (! $orgId) {
            abort(404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'visibility' => 'required|in:public,private',
            'owner_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('banned_at'),
            ],
            'type' => ['nullable', Rule::in(app(LoopTypeRegistry::class)->availableKeys())],
        ]);

        $owner = User::assignable()->findOrFail($data['owner_id']);

        if ($owner->organization_id !== $orgId) {
            abort(403, __('admin.owner_must_belong_to_org'));
        }

        $loop = $this->loopService->createLoop(
            $owner,
            $data['name'],
            $data['description'] ?? null,
            $data['visibility'],
            null,
            Loop::ACCESS_REQUEST,
            null,
            $data['type'] ?? null,
        );

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Boucle créée avec succès.');
    }

    /**
     * Read-only overview of a Loop from the admin.
     *
     * Everything that matters about a Loop on one page — identity, type, cards,
     * governance, Manifesto, invitations — so an administrator can see what a
     * Loop *is* without entering its workspace and without the risk of touching
     * anything. Nothing here mutates.
     */
    public function show(Loop $loop): View
    {
        $this->assertOrgAccess($loop);

        $loop->load([
            'organization', 'creator', 'categories',
            'owners.user', 'activeMembers.user', 'cards',
            'manifesto.user',
        ]);
        $loop->loadCount([
            'activeMembers',
            'invitations',
            'invitations as pending_invitations_count' => fn ($q) => $q->where('status', LoopInvitation::STATUS_PENDING),
        ]);

        $registry = app(LoopTypeRegistry::class);

        return view('admin.loops.show', [
            'boucle' => $loop,
            'typeLabel' => $registry->label($loop->type),
            'presetCards' => $registry->cardsFor($loop->type),
            // activeCardsFor(), not the raw relation: Loops predating the
            // loop_cards table fall back to their type preset, so reading the
            // relation showed them as having no cards while the workspace
            // rendered four.
            'activeCards' => $registry->activeCardsFor($loop),
            'composition' => app(LoopCardCompositionService::class)->compositionFor($loop),
            'cardCatalogue' => config('loop_cards.cards', []),
            // A glance at what each card actually holds, so the overview answers
            // "what is in this Loop" without opening the workspace.
            'cardPreview' => [
                'core.members' => $loop->active_members_count,
                'core.roadmap' => LoopRoadmapItem::where('loop_id', $loop->id)->count(),
                'core.manifesto' => $loop->manifesto?->title,
                'core.ai_summary' => $loop->messages()->count(),
            ],
            'manifestoSources' => app(LoopManifestoService::class)->sourcesFor($loop),
            'invitations' => $loop->invitations()->with('sender')->latest()->limit(10)->get(),
            // The workspace requires an active membership and deliberately has
            // no super-admin bypass (TASK-1073/1074), so the link is offered
            // only when it would actually open — it used to 404.
            'canEnterWorkspace' => auth()->user()?->can('viewWorkspace', $loop) ?? false,
        ]);
    }

    public function edit(Loop $loop): View
    {
        $this->assertOrgAccess($loop);

        if ($this->isSuperAdmin()) {
            $users = User::assignable()
                ->with('organization:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'organization_id']);
        } else {
            $orgId = auth()->user()->organization_id;

            $users = User::assignable()
                ->where('organization_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
        }

        $loop->load(['members.user', 'creator', 'organization']);

        $boucle = $loop;

        return view('admin.loops.edit', compact('boucle', 'users'));
    }

    public function update(Request $request, Loop $loop): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'visibility' => 'required|in:public,private',
        ]);

        $this->loopService->updateLoop($loop, $data);

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Boucle mise à jour.');
    }

    public function addMember(Request $request, Loop $loop): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('organization_id', $loop->organization_id)
                    ->whereNull('banned_at'),
            ],
            'role' => ['nullable', Rule::in(LoopRoleRegistry::CANONICAL)],
        ]);

        $userId = $request->input('user_id');
        $role = (string) $request->input('role', LoopRoleRegistry::MEMBER);

        try {
            $this->loopService->addMemberByUserId($loop, $userId, $role);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Membre ajouté à la boucle.');
    }

    /**
     * Change a member's role from the global admin.
     *
     * Every transition goes through the governance service, so the last-owner
     * invariant is applied here exactly as everywhere else.
     */
    public function updateMemberRole(Request $request, Loop $loop, LoopMember $member): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        abort_if($member->loop_id !== $loop->id, 404);

        $role = (string) $request->input('role');

        $result = app(LoopGovernanceService::class)->changeRole($member, $role);

        return redirect()->route('admin.loops.edit', $loop)->with(
            $result === LoopGovernanceService::RESULT_OK ? 'success' : 'error',
            match ($result) {
                LoopGovernanceService::RESULT_OK => __('loops.governance_changed'),
                LoopGovernanceService::RESULT_LAST_OWNER => __('loops.governance_refused_last_owner'),
                default => __('loops.governance_refused'),
            },
        );
    }

    public function removeMember(Loop $loop, LoopMember $member): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        if ($member->loop_id !== $loop->id) {
            abort(404);
        }

        $result = app(LoopGovernanceService::class)->removeMember($member);

        if ($result === LoopGovernanceService::RESULT_LAST_OWNER) {
            return back()->with('error', __('loops.governance_refused_last_owner'));
        }

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', __('loops.governance_removed'));
    }

    public function files(Loop $loop): View
    {
        $this->assertOrgAccess($loop);

        $messages = $loop->messages()
            ->with('sender')
            ->latest()
            ->paginate(25);

        return view('admin.loops.files', compact('loop', 'messages'));
    }

    public function destroy(Loop $loop): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        $loop->messages()->delete();

        $loop->delete();

        return redirect()->route('admin.loops')
            ->with('success', 'Boucle supprimée.');
    }

    private function assertOrgAccess(Loop $loop): void
    {
        $user = auth()->user();

        if ($user->is_admin) {
            return;
        }

        $orgId = $user->organization_id;

        if (! $orgId || $loop->organization_id !== $orgId) {
            abort(404);
        }
    }
}
