<?php

namespace App\Http\Controllers;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DossierMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('view', $dossier);

        $members = $dossier->dossierMembers()
            ->with('user:id,first_name,name,email')
            ->get()
            ->map(fn (DossierMember $m) => [
                'id' => $m->user_id,
                'name' => $m->user->name,
                'first_name' => $m->user->first_name,
                'email' => $m->user->email,
                'role' => $m->role,
                'added_by' => $m->added_by,
            ]);

        return response()->json(['members' => $members]);
    }

    public function store(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageMembers', $dossier);

        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'role' => 'required|string|in:reader,editor',
        ]);

        $user = User::findOrFail($data['user_id']);

        if ($user->organization_id !== $organization->id) {
            return response()->json(['message' => __('dossiers.member_cross_org')], 422);
        }

        if ($user->id === $dossier->owner_id) {
            return response()->json(['message' => __('dossiers.member_is_owner')], 422);
        }

        $exists = $dossier->dossierMembers()->where('user_id', $user->id)->exists();
        if ($exists) {
            return response()->json(['message' => __('dossiers.member_already')], 422);
        }

        DossierMember::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'user_id' => $user->id,
            'role' => $data['role'],
            'added_by' => $request->user()->id,
        ]);

        $dossier->syncVisibility();

        return response()->json([
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'role' => $data['role'],
            ],
            'message' => __('dossiers.member_added'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageMembers', $dossier);

        $data = $request->validate([
            'role' => 'required|string|in:reader,editor',
        ]);

        $member = $dossier->dossierMembers()->where('user_id', $request->route('member'))->first();
        if (! $member) {
            abort(404);
        }

        $member->update(['role' => $data['role']]);

        return response()->json([
            'member' => [
                'id' => $member->user_id,
                'role' => $member->role,
            ],
            'message' => __('dossiers.member_role_updated'),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageMembers', $dossier);

        $member = $dossier->dossierMembers()->where('user_id', $request->route('member'))->first();
        if (! $member) {
            abort(404);
        }

        $member->delete();

        $dossier->syncVisibility();

        return response()->json([
            'message' => __('dossiers.member_removed'),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('view', $dossier);

        $query = $request->input('q', '');

        $users = User::where('organization_id', $organization->id)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'first_name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
            ]);

        return response()->json(['users' => $users]);
    }

    private function resolveDossier(mixed $dossier): Dossier
    {
        if ($dossier instanceof Dossier) {
            return $dossier;
        }

        return Dossier::query()->whereKey($dossier)->firstOrFail();
    }

    private function currentOrganizationOrFail()
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        return $organization;
    }

    private function ensureDossierBelongsToCurrentOrganization(Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($dossier->organization_id !== $organization->id) {
            abort(404);
        }
    }
}
