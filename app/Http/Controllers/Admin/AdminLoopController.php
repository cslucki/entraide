<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminLoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        $orgId = $user->organization_id;

        if (! $orgId) {
            abort(404);
        }

        $loops = Loop::where('organization_id', $orgId)
            ->with('creator:id,name,email')
            ->withCount('activeMembers')
            ->latest()
            ->paginate(25);

        $loops->load(['messages' => fn ($q) => $q->latest()->limit(1)]);

        return view('admin.loops.index', compact('loops'));
    }

    public function create(): View
    {
        $orgId = auth()->user()->organization_id;

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
            abort(403, 'Owner must be in the same organization.');
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

        $orgId = auth()->user()->organization_id;

        $users = User::where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $loop->load(['members.user', 'creator']);

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

        $loop->delete();

        return redirect()->route('admin.loops')
            ->with('success', 'Boucle supprimée.');
    }

    private function assertOrgAccess(Loop $loop): void
    {
        $orgId = auth()->user()->organization_id;

        if (! $orgId || $loop->organization_id !== $orgId) {
            abort(404);
        }
    }
}
