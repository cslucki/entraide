<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $query = Loop::with(['creator:id,name,email', 'organization:id,name,slug'])
            ->withCount('activeMembers')
            ->latest();

        if ($selectedOrganizationId !== 'all') {
            $query->where('organization_id', $selectedOrganizationId);
        }

        $loops = $query->paginate(25)->withQueryString();

        $loops->load(['messages' => fn ($q) => $q->latest()->limit(1)]);

        return view('admin.loops.index', compact('loops', 'organizations', 'selectedOrganizationId'));
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

            $users = User::with('organization:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'organization_id']);

            return view('admin.loops.create', compact('users', 'organizations'));
        }

        $orgId = $user->organization_id;

        if (! $orgId) {
            abort(404);
        }

        $users = User::where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.loops.create', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = auth()->user();

        if ($this->isSuperAdmin()) {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:5000',
                'visibility' => 'required|in:public,private',
                'owner_id' => 'required|exists:users,id',
                'organization_id' => 'required|exists:organizations,id',
            ]);

            $owner = User::findOrFail($data['owner_id']);

            if ($owner->organization_id !== $data['organization_id']) {
                abort(403, __('admin.owner_must_belong_to_org'));
            }

            $loop = $this->loopService->createLoopForOrg(
                $owner,
                $data['organization_id'],
                $data['name'],
                $data['description'] ?? null,
                $data['visibility'],
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
            'owner_id' => 'required|exists:users,id',
        ]);

        $owner = User::findOrFail($data['owner_id']);

        if ($owner->organization_id !== $orgId) {
            abort(403, __('admin.owner_must_belong_to_org'));
        }

        $loop = $this->loopService->createLoop(
            $owner,
            $data['name'],
            $data['description'] ?? null,
            $data['visibility'],
        );

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Boucle créée avec succès.');
    }

    public function edit(Loop $loop): View
    {
        $this->assertOrgAccess($loop);

        if ($this->isSuperAdmin()) {
            $users = User::with('organization:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'organization_id']);
        } else {
            $orgId = auth()->user()->organization_id;

            $users = User::where('organization_id', $orgId)
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
            'user_id' => 'required|exists:users,id',
        ]);

        $userId = $request->input('user_id');

        try {
            $this->loopService->addMemberByUserId($loop, $userId);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Membre ajouté à la boucle.');
    }

    public function removeMember(Loop $loop, LoopMember $member): RedirectResponse
    {
        $this->assertOrgAccess($loop);

        if ($member->loop_id !== $loop->id) {
            abort(404);
        }

        try {
            $this->loopService->removeMember($member);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.loops.edit', $loop)
            ->with('success', 'Membre retiré de la boucle.');
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

        if ($loop->hasContent()) {
            return redirect()->route('admin.loops')
                ->with('error', 'Impossible de supprimer cette boucle : elle contient des messages. Archivez-la plutôt.');
        }

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
