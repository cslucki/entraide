<?php

namespace App\Http\Controllers;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\LoopMember;
use App\Models\User;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DossierMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('view', $dossier);

        // TASK-1130 passe 4 : un enfant ne porte ni owner_id ni
        // dossier_members a lui — toute lecture/ecriture de membres doit
        // viser la racine qui gouverne, quel que soit l'appelant (vue,
        // API directe, futur client). Deleguer ici, dans la primitive,
        // evite que ce soit a chaque appelant de s'en souvenir.
        // Le partage vit sur le Dossier DESIGNE, plus sur son governing
        // root : remonter ici posait le membre sur « Mes documents » et
        // ouvrait tout l'espace personnel (TASK-1136). La gouvernance
        // (proprietaire, regime) continue de se demander a la racine — c'est
        // `isOwner()` qui s'en charge, et il n'a pas change.

        // Dossier racine : les personnes qui y accedent sont les membres actifs
        // de la Boucle — dossier_members est vide par construction, et le lire
        // aurait rendu une liste vide a un Dossier bien habite. Lecture seule :
        // les acces se gerent depuis la Boucle, jamais d'ici.
        if ($dossier->isLoopDossier()) {
            $roles = app(LoopRoleRegistry::class);

            $members = ($dossier->loop?->activeMembers() ?? LoopMember::query()->whereRaw('1 = 0'))
                ->with('user:id,first_name,name,organization_id,banned_at')
                ->orderByRaw("case role when 'owner' then 0 when 'facilitator' then 1 else 2 end")
                ->orderBy('joined_at')
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user_id : null,
                    'name' => $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user->name : __('profile.deactivated_user'),
                    'first_name' => $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user->first_name : null,
                    'email' => null,
                    'role' => 'loop_'.$roles->canonical($m->role),
                    'added_by' => null,
                ]);

            return response()->json([
                'members' => $members,
                'inherited_members' => [],
                'managed_by_loop' => true,
            ]);
        }

        $isOwner = $request->user()->id === $dossier->governingDossier()->owner_id;

        $members = $dossier->dossierMembers()
            ->with('user:id,first_name,name,organization_id,banned_at'.($isOwner ? ',email' : ''))
            ->get()
            ->map(fn (DossierMember $m) => [
                'id' => $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user_id : null,
                'name' => $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user->name : __('profile.deactivated_user'),
                'first_name' => $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user->first_name : null,
                'email' => $isOwner && $m->user?->isDisplayableIn($dossier->organization_id) ? $m->user->email : null,
                'role' => $m->role,
                'added_by' => $m->added_by,
            ]);

        $directUserIds = $dossier->dossierMembers()->pluck('user_id')->all();
        $anchorIds = array_values(array_filter(
            $dossier->sharingAnchorIds(),
            fn (string $anchorId) => $anchorId !== $dossier->getKey(),
        ));

        $inheritedMembers = collect();

        if ($anchorIds !== []) {
            $anchorPositions = array_flip($anchorIds);

            $inheritedMembers = DossierMember::query()
                ->whereIn('dossier_id', $anchorIds)
                ->whereNotIn('user_id', $directUserIds)
                ->with([
                    'user:id,first_name,name,organization_id,banned_at'.($isOwner ? ',email' : ''),
                    'dossier:id,name',
                ])
                ->get()
                ->groupBy('user_id')
                ->map(function ($grants) use ($anchorPositions, $dossier, $isOwner) {
                    // La policy accorde l'edition si l'une des ancres actives
                    // porte editor. Afficher ce role effectif evite qu'un
                    // panneau annonce Lecteur a une personne qui peut editer.
                    $effectiveRole = $grants->contains('role', DossierMember::ROLE_EDITOR)
                        ? DossierMember::ROLE_EDITOR
                        : DossierMember::ROLE_READER;
                    $source = $grants
                        ->where('role', $effectiveRole)
                        ->sortBy(fn (DossierMember $grant) => $anchorPositions[$grant->dossier_id] ?? PHP_INT_MAX)
                        ->first();
                    $user = $source?->user;
                    $displayable = $user?->isDisplayableIn($dossier->organization_id) ?? false;

                    return [
                        'id' => $displayable ? $source->user_id : null,
                        'name' => $displayable ? $user->name : __('profile.deactivated_user'),
                        'first_name' => $displayable ? $user->first_name : null,
                        'email' => $isOwner && $displayable ? $user->email : null,
                        'role' => $effectiveRole,
                        'inherited_from' => [
                            'id' => $source->dossier_id,
                            'name' => $source->dossier?->name,
                        ],
                    ];
                })
                ->values();
        }

        return response()->json([
            'members' => $members,
            'inherited_members' => $inheritedMembers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageMembers', $dossier);
        // Le partage vit sur le Dossier DESIGNE, plus sur son governing
        // root : remonter ici posait le membre sur « Mes documents » et
        // ouvrait tout l'espace personnel (TASK-1136). La gouvernance
        // (proprietaire, regime) continue de se demander a la racine — c'est
        // `isOwner()` qui s'en charge, et il n'a pas change.

        $data = $request->validate([
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', $organization->id)
                    ->whereNull('banned_at'),
            ],
            // Un nouvel acces commence toujours en Lecteur. Le role ne peut
            // etre choisi qu'apres la creation explicite du membership.
            'role' => 'sometimes|string|in:reader,editor',
        ]);

        $user = User::assignable()->findOrFail($data['user_id']);

        if ($user->organization_id !== $organization->id) {
            return response()->json(['message' => __('dossiers.member_cross_org')], 422);
        }

        if ($user->id === $dossier->governingDossier()->owner_id) {
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
            'role' => DossierMember::ROLE_READER,
            'added_by' => $request->user()->id,
        ]);

        $dossier->syncVisibility();

        return response()->json([
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'role' => DossierMember::ROLE_READER,
            ],
            'message' => __('dossiers.member_added'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageMembers', $dossier);
        // Le partage vit sur le Dossier DESIGNE, plus sur son governing
        // root : remonter ici posait le membre sur « Mes documents » et
        // ouvrait tout l'espace personnel (TASK-1136). La gouvernance
        // (proprietaire, regime) continue de se demander a la racine — c'est
        // `isOwner()` qui s'en charge, et il n'a pas change.

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
        // Le partage vit sur le Dossier DESIGNE, plus sur son governing
        // root : remonter ici posait le membre sur « Mes documents » et
        // ouvrait tout l'espace personnel (TASK-1136). La gouvernance
        // (proprietaire, regime) continue de se demander a la racine — c'est
        // `isOwner()` qui s'en charge, et il n'a pas change.

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
        $this->authorize('manageMembers', $dossier);
        // Le partage vit sur le Dossier DESIGNE, plus sur son governing
        // root : remonter ici posait le membre sur « Mes documents » et
        // ouvrait tout l'espace personnel (TASK-1136). La gouvernance
        // (proprietaire, regime) continue de se demander a la racine — c'est
        // `isOwner()` qui s'en charge, et il n'a pas change.

        $query = preg_replace('/\s+/', ' ', trim($request->input('q', '')));
        $ownerId = $dossier->governingDossier()->owner_id;
        $memberIds = $dossier->dossierMembers()->pluck('user_id')->all();

        $likePattern = '%'.$query.'%';

        $users = User::assignable()
            ->where('organization_id', $organization->id)
            ->where('id', '!=', $ownerId)
            ->whereNotIn('id', $memberIds)
            ->where(function ($q) use ($likePattern) {
                $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$likePattern])
                    ->orWhereRaw('LOWER(first_name) LIKE LOWER(?)', [$likePattern])
                    ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$likePattern])
                    ->orWhereRaw("LOWER(first_name || ' ' || name) LIKE LOWER(?)", [$likePattern])
                    ->orWhereRaw("LOWER(name || ' ' || first_name) LIKE LOWER(?)", [$likePattern]);
            })
            ->limit(10)
            ->get(['id', 'name', 'first_name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'email' => $user->email,
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
