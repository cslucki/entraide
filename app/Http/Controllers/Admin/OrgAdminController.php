<?php

namespace App\Http\Controllers\Admin;

use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\NervousSystemCoverage;
use App\Ai\ProviderResolver;
use App\Http\Controllers\Controller;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\BugReport;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\LoginLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\Referral;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\SystemEmailTemplate;
use App\Models\Theme;
use App\Models\Transaction;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiConsumption;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Ai\OrganizationDoctrineSandbox;
use App\Services\Dossiers\OrganizationRagOverview;
use App\Services\LoopGovernanceService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\Loops\PresetException;
use App\Services\LoopService;
use App\Services\TranslationOverrideService;
use App\Services\TranslationService;
use App\Services\UserDataLifecycleRegistry;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrgAdminController extends Controller
{
    public function dashboard(Organization $organization): View
    {
        $orgId = $organization->id;
        $stats = [
            'users' => User::where('organization_id', $orgId)->count(),
            'loops' => Loop::where('organization_id', $orgId)->count(),
            'services' => Service::where('organization_id', $orgId)->where('status', 'active')->count(),
            'requests' => ServiceRequest::where('organization_id', $orgId)->count(),
        ];
        $recentUsers = User::where('organization_id', $orgId)->latest()->limit(5)->get();

        return view('admin.org.dashboard', [
            'organization' => $organization,
            'stats' => $stats,
            'recentUsers' => $recentUsers,
        ]);
    }

    public function services(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Service::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active')->whereNull('deleted_at'),
                'paused' => $query->where('status', 'paused')->whereNull('deleted_at'),
                'deleted' => $query->onlyTrashed(),
                default => null,
            };
        }

        $services = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.services', [
            'organization' => $organization,
            'services' => $services,
        ]);
    }

    public function requests(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = ServiceRequest::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.requests', [
            'organization' => $organization,
            'requests' => $requests,
        ]);
    }

    public function transactions(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Transaction::where('organization_id', $orgId)->with(['buyer', 'seller', 'service']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('buyer', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'))
                    ->orWhereHas('seller', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $transactions = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.transactions', [
            'organization' => $organization,
            'transactions' => $transactions,
        ]);
    }

    public function closeRequest(Organization $organization, string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::where('organization_id', $organization->id)->findOrFail($serviceRequest);
        $serviceRequest->update(['status' => 'closed']);

        return back()->with('success', 'Demande clôturée.');
    }

    public function loops(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        // A listing, nothing more (TASK-1079): editing and member management moved
        // to a dedicated page, so there is no reason to hydrate every member of
        // every Loop here. Counters are aggregated in SQL.
        $query = Loop::where('organization_id', $orgId)
            ->with(['creator', 'owner.user', 'owners.user', 'cards'])
            ->withCount([
                'activeMembers',
                'invitations',
                'invitations as pending_invitations_count' => fn ($q) => $q->where('status', LoopInvitation::STATUS_PENDING),
            ]);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active'),
                'archived' => $query->where('status', 'archived'),
                default => null,
            };
        }

        $loops = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.loops', [
            'organization' => $organization,
            'loops' => $loops,
            'loopTypes' => app(LoopTypeRegistry::class)->all(),
        ]);
    }

    public function messages(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Message::where('organization_id', $orgId)->with(['sender', 'transaction']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('body', 'like', '%'.$request->search.'%')
                    ->orWhereHas('sender', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $messages = $query->latest('created_at')->paginate(25)->withQueryString();

        return view('admin.org.messages', [
            'organization' => $organization,
            'messages' => $messages,
        ]);
    }

    public function blog(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = BlogPost::where('organization_id', $orgId)->with(['user', 'category']);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'draft' => $query->where('status', 'draft'),
                'published' => $query->where('status', 'published'),
                default => null,
            };
        }

        $posts = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.blog', [
            'organization' => $organization,
            'posts' => $posts,
        ]);
    }

    /**
     * Edit a Loop from the Organization admin: name, description, type.
     *
     * Strictly tenant-scoped, and deliberately narrow — the full editing
     * surface stays in LoopController rather than being duplicated here.
     */
    /**
     * Dedicated edit page for one Loop.
     *
     * Everything that acts on a Loop lives here rather than being crammed into
     * the listing table: identity, type and its card composition, members, and
     * the invitation figures.
     */
    public function editLoop(Organization $organization, Loop $loop): View
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $registry = app(LoopTypeRegistry::class);

        $loop->load(['creator', 'owner.user', 'activeMembers.user', 'cards']);
        $loop->loadCount([
            'activeMembers',
            'invitations',
            'invitations as pending_invitations_count' => fn ($q) => $q->where('status', LoopInvitation::STATUS_PENDING),
        ]);

        $memberIds = $loop->activeMembers->pluck('user_id');

        return view('admin.org.loop-edit', [
            'organization' => $organization,
            'loop' => $loop,
            // `selectableFor` et non `all()` : l'ecran offrait des types que le
            // serveur refuse desormais, donc un cul-de-sac. Il garde celui que
            // la Boucle porte, meme ferme — l'ecran plateforme fait deja ainsi.
            //
            // **Dans la portee de l'Organization** : sans elle, un type qu'elle
            // a cree n'etait pas propose chez elle, et un type qu'elle a renomme
            // s'affichait sous son nom commun.
            'loopTypes' => $registry->selectableFor($loop->type, $organization),
            'currentType' => $registry->resolve($loop->type),
            // What the type prescribes, so the admin can tell the baseline apart
            // from what this Loop added on its own. **Dans la portee de
            // l'Organization** : c'est son socle surcharge qui fait baseline
            // ici, pas celui de la Plateforme — la dette laissee par TASK-1120.
            'presetCards' => $registry->cardsFor($loop->type, $organization),
            // La capacite reelle **de la route visee** : configureLoop() exige
            // l'appartenance a l'Organization (garde tenant stricte), la ou ce
            // present ecran laisse passer le SuperAdmin. Sans ce troisieme
            // terme, un SuperAdmin d'une autre Organization voyait un bouton
            // qui menait a une 404 — constate en recette. Lui a son ecran
            // plateforme ; le bouton scope est pour l'admin d'Organization.
            'canConfigureCards' => ! $loop->isArchived()
                && auth()->user()?->organization_id === $organization->id
                && app(LoopPresetConfigurator::class)->canConfigure(auth()->user(), $loop),
            'composition' => app(LoopCardCompositionService::class)->compositionFor($loop),
            'candidates' => User::assignable()
                ->where('organization_id', $organization->id)
                ->whereNotIn('id', $memberIds)
                ->orderBy('name')
                ->get(['id', 'name', 'first_name', 'email']),
        ]);
    }

    /**
     * Turn one card on or off, within this Organization only.
     *
     * The tenant check comes first and is not negotiable: a forged Loop id from
     * another Organization is a 404 before anything else is read. The same
     * service as the platform admin does the writing — the business rules live
     * in one place, not in two controllers.
     */
    public function updateLoopCards(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);
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

    public function updateLoop(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $registry = app(LoopTypeRegistry::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', 'string'],
        ]);

        if (! $registry->exists($data['type'])) {
            return back()->with('error', __('loops.type_invalid'));
        }

        // Meme regle que les deux autres chemins, lue au registre : un type
        // retire des choix ne s'assigne pas, mais garder le sien reste permis.
        if (! $registry->isAssignableTo($data['type'], $loop->type)) {
            return back()->with('error', __('loops.type_unavailable'));
        }

        $loop->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
        ]);

        $added = $registry->applyPreset($loop->fresh());

        return redirect()
            ->route('organization.admin.loops.edit', ['organization' => $organization->slug, 'loop' => $loop->id])
            ->with('success', $added === []
            ? __('loops.type_changed_no_card')
            : __('loops.type_changed', [
                'cards' => collect($added)->map(fn ($k) => $registry->cardLabel($k))->implode(', '),
            ]));
    }

    /**
     * Change a member's role from the Organization admin.
     *
     * Tenant-scoped twice over: the Loop must belong to this Organization, and
     * the membership must belong to that Loop. Same governance service as the
     * global screen — the business rule is not duplicated.
     */
    public function updateLoopMemberRole(Request $request, Organization $organization, Loop $loop, LoopMember $member): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);
        abort_if($member->loop_id !== $loop->id, 404);

        $result = app(LoopGovernanceService::class)->changeRole($member, (string) $request->input('role'));

        return redirect()
            ->route('organization.admin.loops.edit', ['organization' => $organization->slug, 'loop' => $loop->id])
            ->with(
                $result === LoopGovernanceService::RESULT_OK ? 'success' : 'error',
                match ($result) {
                    LoopGovernanceService::RESULT_OK => __('loops.governance_changed'),
                    LoopGovernanceService::RESULT_LAST_OWNER => __('loops.governance_refused_last_owner'),
                    default => __('loops.governance_refused'),
                },
            );
    }

    /**
     * Archiver ou reactiver une Boucle depuis l'administration d'Organization.
     *
     * La mutation elle-meme a quitte ce controleur : elle vit dans
     * LoopLifecycleService, avec les trois autres ecrans qui la faisaient chacun
     * a leur maniere. Celui-ci ne verifiait aucune permission — il la demande
     * desormais comme tout le monde.
     */
    /**
     * Le configurateur, vu depuis l'Organization.
     *
     * Meme service, meme vue, memes regles que l'ecran plateforme : seules les
     * routes changent. Dupliquer l'ecran aurait garanti qu'ils divergent.
     */
    public function configureLoop(Request $request, Organization $organization, Loop $loop): View
    {
        abort_if($loop->organization_id !== $organization->id, 404);
        // Second verrou : le middleware du prefixe admin refuse deja quelqu'un
        // d'une autre Organization. On ne s'en remet pas a lui seul — la
        // strictesse tenant ne se deduit pas d'une couche qu'on ne controle pas
        // depuis ici.
        abort_if($request->user()?->organization_id !== $organization->id, 404);

        $configurator = app(LoopPresetConfigurator::class);

        abort_unless($configurator->canConfigure($request->user(), $loop), 403);

        return view('admin.loops.configure', [
            'loop' => $loop,
            'composition' => $configurator->describe($loop),
            // Dans la portee de l'Organization, comme le chemin plateforme.
            'types' => app(LoopTypeRegistry::class)->selectableFor($loop->type, $organization),
            'organization' => $organization,
            'backUrl' => route('organization.admin.loops.edit', [
                'organization' => $organization->slug, 'loop' => $loop->id,
            ]),
            'scopedRoutes' => [
                'compose' => route('organization.admin.loops.compose', [
                    'organization' => $organization->slug, 'loop' => $loop->id,
                ]),
                'preset' => route('organization.admin.loops.preset.apply', [
                    'organization' => $organization->slug, 'loop' => $loop->id,
                ]),
            ],
        ]);
    }

    public function composeLoopCards(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'action' => 'required|in:enable,disable,replace,restore,promote,demote',
            'card_key' => 'nullable|string',
            'incoming_key' => 'nullable|string',
        ]);

        $configurator = app(LoopPresetConfigurator::class);
        $user = $request->user();

        try {
            $message = match ($data['action']) {
                'enable' => tap(__('loops.preset_enabled'), fn () => $configurator->enable($user, $loop, $data['card_key'] ?? '')),
                'disable' => tap(__('loops.preset_disabled'), fn () => $configurator->disable($user, $loop, $data['card_key'] ?? '')),
                'replace' => tap(__('loops.preset_replaced'), fn () => $configurator->replace($user, $loop, $data['card_key'] ?? '', $data['incoming_key'] ?? '')),
                'promote' => tap(__('loops.tools_promoted'), fn () => $configurator->promote($user, $loop, $data['card_key'] ?? '')),
                'demote' => tap(__('loops.tools_demoted'), fn () => $configurator->demote($user, $loop, $data['card_key'] ?? '')),
                default => __('loops.preset_restored', ['count' => count($configurator->restorePreset($user, $loop))]),
            };
        } catch (PresetException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    public function applyLoopPreset(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'type' => 'required|string',
            'deactivate_absent' => 'nullable|boolean',
        ]);

        try {
            app(LoopPresetConfigurator::class)->applyPreset(
                $request->user(), $loop, $data['type'], (bool) ($data['deactivate_absent'] ?? false),
            );
        } catch (PresetException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('loops.preset_applied', [
            'type' => app(LoopTypeRegistry::class)->label($data['type'], $loop->organization),
        ]));
    }

    /**
     * La politique de composition de l'Organization.
     *
     * Deux valeurs, et c'est tout : verrouille, ou proprietaires autorises.
     * Une delegation plus fine demanderait sa propre table, et personne ne l'a
     * demandee.
     */
    public function updateCompositionPolicy(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($request->user()?->is_admin || $organization->admin_id === $request->user()?->id, 403);

        $data = $request->validate([
            'policy' => ['required', Rule::in(Organization::COMPOSITION_POLICIES)],
        ]);

        $organization->update(['loop_composition_policy' => $data['policy']]);

        return back()->with('success', __('loops.preset_policy_saved'));
    }

    public function toggleLoopActive(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $lifecycle = app(LoopLifecycleService::class);
        $wasActive = $loop->isActive();

        $result = $wasActive
            ? $lifecycle->archive($request->user(), $loop)
            : $lifecycle->reactivate($request->user(), $loop);

        if ($result === LoopLifecycleService::RESULT_DENIED) {
            abort(403);
        }

        $action = $wasActive ? 'archived' : 'reactivated';

        return back()->with('success', __("navigation.org_admin_loop_{$action}"));
    }

    public function addLoopMember(Request $request, Organization $organization, Loop $loop): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('organization_id', $organization->id)
                    ->whereNull('banned_at'),
            ],
            'role' => ['nullable', Rule::in(LoopRoleRegistry::CANONICAL)],
        ]);

        $user = User::assignable()->findOrFail($data['user_id']);
        // Checked again on the resolved model, not only through the validation
        // rule: tenant strictness is not something to infer from a query scope.
        abort_if($user->organization_id !== $organization->id, 422, __('loops.not_member'));

        try {
            app(LoopService::class)->addMemberByUserId($loop, $data['user_id'], $data['role'] ?? LoopRoleRegistry::MEMBER);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('loops.member_added'));
    }

    public function removeLoopMember(Organization $organization, Loop $loop, LoopMember $member): RedirectResponse
    {
        abort_if($loop->organization_id !== $organization->id, 404);
        abort_if($member->loop_id !== $loop->id, 404);

        app(LoopService::class)->removeMember($member);

        return back()->with('success', __('loops.member_removed'));
    }

    public function publishBlogPost(Organization $organization, BlogPost $blogPost): RedirectResponse
    {
        abort_if($blogPost->organization_id !== $organization->id, 404);

        $blogPost->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', __('navigation.org_admin_post_published'));
    }

    public function users(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = User::where('organization_id', $orgId);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'banned' => $query->whereNotNull('banned_at'),
                'active' => $query->whereNull('banned_at'),
                default => null,
            };
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'name' => $query->orderBy('name', $direction),
            'email' => $query->orderBy('email', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'points_balance' => $query->orderBy('points_balance', $direction),
            'is_admin' => $query->orderBy('is_admin', $direction),
            'status' => $query->orderByRaw('banned_at IS NULL '.($direction === 'asc' ? 'ASC' : 'DESC').', banned_at '.$direction),
            default => $query->latest(),
        };

        $users = $query->paginate(25)->withQueryString();

        return view('admin.org.users', [
            'organization' => $organization,
            'users' => $users,
        ]);
    }

    public function toggleUserBan(Organization $organization, User $user): RedirectResponse
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $user->update([
            'banned_at' => $user->banned_at ? null : now(),
        ]);

        $action = $user->banned_at ? 'banned' : 'unbanned';

        return back()->with('success', __("navigation.org_admin_user_{$action}"));
    }

    // ── User deletion dry-run (org-admin) ─────────────────────────────────────

    public function deletePreview(Organization $organization, User $user): View
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $counts = $this->countUserRelations($organization, $user);

        $sameOrgUsers = User::where('organization_id', $organization->id)
            ->assignable()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('admin.org.users.delete-preview', compact('organization', 'user', 'counts', 'sameOrgUsers'));
    }

    public function deleteUser(Request $request, Organization $organization, User $user): View
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'confirmation' => 'required|string',
            'transfer_to' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', $organization->id)
                    ->whereNull('banned_at'),
            ],
        ]);

        if ($data['confirmation'] !== $user->name) {
            return $this->deletePreview($organization, $user);
        }

        $counts = $this->countUserRelations($organization, $user);

        if (! empty($data['transfer_to'])) {
            $transferTo = User::assignable()->find($data['transfer_to']);
            if ($transferTo && $transferTo->organization_id === $organization->id) {
                $counts['transfer'] = $this->estimateTransferCounts($organization, $user, $data['transfer_to']);
            }
        }

        $counts['preview_only'] = true;

        $sameOrgUsers = User::where('organization_id', $organization->id)
            ->assignable()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('admin.org.users.delete-preview', compact('organization', 'user', 'counts', 'sameOrgUsers'));
    }

    private function countUserRelations(Organization $organization, User $user): array
    {
        return app(UserDataLifecycleRegistry::class)->preview($user, $organization);
    }

    private function estimateTransferCounts(Organization $organization, User $user, string $transferToId): array
    {
        return app(UserDataLifecycleRegistry::class)->transferEstimate($user, $transferToId, $organization);
    }

    public function categories(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = Category::where('organization_id', $orgId)
            ->with(['skills'])
            ->withCount(['services', 'serviceRequests', 'skills']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name_b2c', 'like', '%'.$request->search.'%')
                    ->orWhere('name_b2b', 'like', '%'.$request->search.'%');
            });
        }

        $categories = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.categories', [
            'organization' => $organization,
            'categories' => $categories,
        ]);
    }

    public function createCategory(Organization $organization): View
    {
        return view('admin.org.categories-form', [
            'organization' => $organization,
            'category' => null,
        ]);
    }

    public function storeCategory(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'name_b2c' => 'required|string|max:100',
            'name_b2b' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $data['organization_id'] = $organization->id;
        $category = Category::create($data);

        if ($request->has('skills')) {
            $skillNames = array_filter($request->input('skills', []), fn ($name) => ! empty(trim($name)));
            foreach ($skillNames as $skillName) {
                $category->skills()->create([
                    'name' => $skillName,
                    'slug' => Str::slug($skillName),
                    'organization_id' => $organization->id,
                ]);
            }
        }

        return redirect()->route('organization.admin.categories', [
            'organization' => $organization->slug,
        ])->with('success', 'Catégorie créée.');
    }

    public function editCategory(Organization $organization, Category $category): View
    {
        abort_if($category->organization_id !== $organization->id, 404);

        $category->load('skills');

        return view('admin.org.categories-form', [
            'organization' => $organization,
            'category' => $category,
        ]);
    }

    public function updateCategory(Request $request, Organization $organization, Category $category): RedirectResponse
    {
        abort_if($category->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'name_b2c' => 'required|string|max:100',
            'name_b2b' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $data['slug'] = Str::slug($data['name_b2c']);
        $category->update($data);

        if ($request->has('skills')) {
            $skillNames = array_filter($request->input('skills', []), fn ($name) => ! empty(trim($name)));
            $existingSkills = $category->skills->keyBy('name');
            foreach ($skillNames as $skillName) {
                if (! $existingSkills->has($skillName)) {
                    $category->skills()->create([
                        'name' => $skillName,
                        'slug' => Str::slug($skillName),
                        'organization_id' => $organization->id,
                    ]);
                }
            }
            $skillsToDelete = $category->skills->whereNotIn('name', $skillNames);
            $skillsToDelete->each->delete();
        }

        return redirect()->route('organization.admin.categories', [
            'organization' => $organization->slug,
        ])->with('success', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Organization $organization, Category $category): RedirectResponse
    {
        abort_if($category->organization_id !== $organization->id, 404);

        if ($category->services()->count() > 0 || $category->serviceRequests()->count() > 0) {
            return back()->with('error', 'Impossible de supprimer une catégorie utilisée par des services ou demandes.');
        }

        $category->skills()->delete();
        $category->delete();

        return redirect()->route('organization.admin.categories', [
            'organization' => $organization->slug,
        ])->with('success', 'Catégorie supprimée.');
    }

    public function storeCategorySkill(Request $request, Organization $organization, Category $category): RedirectResponse
    {
        abort_if($category->organization_id !== $organization->id, 404);

        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        Skill::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'organization_id' => $organization->id,
        ]);

        return back()->with('success', 'Compétence ajoutée.');
    }

    public function destroyCategorySkill(Organization $organization, Skill $skill): RedirectResponse
    {
        abort_if($skill->organization_id !== $organization->id, 404);

        $skill->delete();

        return back()->with('success', 'Compétence supprimée.');
    }

    public function reports(Request $request, Organization $organization): View
    {
        $orgId = $organization->id;
        $query = BugReport::where('organization_id', $orgId)->with('reporter');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('reason', 'like', '%'.$request->search.'%');
        }

        $bugReports = $query->latest()->paginate(25)->withQueryString();

        return view('admin.org.reports', [
            'organization' => $organization,
            'bugReports' => $bugReports,
        ]);
    }

    public function resolveBugReport(Organization $organization, BugReport $bugReport): RedirectResponse
    {
        abort_if($bugReport->organization_id !== $organization->id, 404);

        $bugReport->update([
            'status' => 'resolved',
            'fixed_at' => now(),
        ]);

        return back()->with('success', __('navigation.org_admin_report_resolved'));
    }

    public function invitations(Organization $organization): View
    {
        $orgId = $organization->id;
        $referrals = Referral::where('organization_id', $orgId)
            ->with(['referrer', 'referredUser'])
            ->latest()
            ->paginate(25);

        return view('admin.org.invitations', [
            'organization' => $organization,
            'referrals' => $referrals,
        ]);
    }

    public function translations(
        Request $request,
        Organization $organization,
        TranslationOverrideService $overrideService,
    ): View {
        $translationService = app(TranslationService::class);
        $entries = $translationService->all();
        $groups = collect($translationService->getGroups());

        $orgId = $organization->id;
        $activeGroup = $request->input('group', '_all');
        $activeStatus = $request->input('status', '_all');
        $search = $request->input('search', '');

        $overrides = TranslationOverride::query()
            ->forOrganization($orgId)
            ->with(['createdBy'])
            ->latest()
            ->get()
            ->keyBy(fn (TranslationOverride $o) => "{$o->group}.{$o->key}:{$o->locale}");

        $entries = $entries->filter(function ($entry) use ($activeGroup, $activeStatus, $search, $overrides): bool {
            if ($activeGroup !== '_all' && $entry['group'] !== $activeGroup) {
                return false;
            }
            if ($activeStatus === 'OVERRIDDEN') {
                $hasOverride = isset($overrides["{$entry['group']}.{$entry['key']}:fr"])
                    || isset($overrides["{$entry['group']}.{$entry['key']}:en"]);
                if (! $hasOverride) {
                    return false;
                }
            } elseif ($activeStatus !== '_all' && $entry['status'] !== $activeStatus) {
                return false;
            }
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $fr = is_array($entry['fr'] ?? null) ? '' : (string) ($entry['fr'] ?? '');
                $en = is_array($entry['en'] ?? null) ? '' : (string) ($entry['en'] ?? '');
                if (! str_contains(mb_strtolower($entry['group']), $needle)
                    && ! str_contains(mb_strtolower($entry['key']), $needle)
                    && ! str_contains(mb_strtolower($fr), $needle)
                    && ! str_contains(mb_strtolower($en), $needle)) {
                    return false;
                }
            }

            return true;
        })->values();

        $overriddenCount = $entries->filter(fn ($e) => isset($overrides["{$e['group']}.{$e['key']}:fr"])
            || isset($overrides["{$e['group']}.{$e['key']}:en"])
        )->count();

        $remainingCount = $entries->count() - $overriddenCount;

        $stats = [
            'total' => $entries->count(),
            'ok' => $entries->where('status', 'OK')->count(),
            'missing_fr' => $entries->where('status', 'MISSING_FR')->count(),
            'missing_en' => $entries->where('status', 'MISSING_EN')->count(),
            'overridden' => $overriddenCount,
            'remaining' => $remainingCount,
        ];

        return view('admin.org.translations', [
            'organization' => $organization,
            'groups' => $groups,
            'entries' => $entries,
            'overrides' => $overrides,
            'activeGroup' => $activeGroup,
            'activeStatus' => $activeStatus,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    public function storeOverride(
        Request $request,
        Organization $organization,
        TranslationOverrideService $overrideService,
    ): RedirectResponse {
        $validated = $request->validate([
            'locale' => 'required|in:fr,en',
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
            'value' => 'required|string|max:1000',
        ]);

        $overrideService->set(
            group: $validated['group'],
            key: $validated['key'],
            locale: $validated['locale'],
            value: $validated['value'],
            organization: $organization,
            userId: auth()->id(),
        );

        return redirect()->route('organization.admin.translations', [
            'organization' => $organization->slug,
        ])->with('success', __('navigation.org_admin_translation_created'));
    }

    public function deactivateOverride(
        Organization $organization,
        TranslationOverride $translationOverride,
        TranslationOverrideService $overrideService,
    ): RedirectResponse {
        abort_if($translationOverride->organization_id !== $organization->id, 404);

        $overrideService->deactivate(
            group: $translationOverride->group,
            key: $translationOverride->key,
            locale: $translationOverride->locale,
            organization: $organization,
        );

        return back()->with('success', __('navigation.org_admin_translation_deactivated'));
    }

    public function resetOverride(
        Request $request,
        Organization $organization,
        TranslationOverrideService $overrideService,
    ): RedirectResponse {
        $validated = $request->validate([
            'group' => 'required|string|max:100',
            'key' => 'required|string|max:100',
        ]);

        $orgId = $organization->id;
        $count = TranslationOverride::query()
            ->where('organization_id', $orgId)
            ->where('group', $validated['group'])
            ->where('key', $validated['key'])
            ->where('is_active', true)
            ->count();

        if ($count === 0) {
            return back()->with('error', __('navigation.org_admin_translation_no_active'));
        }

        foreach (['fr', 'en'] as $locale) {
            $overrideService->deactivate(
                group: $validated['group'],
                key: $validated['key'],
                locale: $locale,
                organization: $organization,
            );
        }

        return back()->with('success', __('navigation.org_admin_translation_reset_done'));
    }

    /**
     * TASK-1212 (IA P4-lite) : configuration IA de l'Organization. Le
     * credential n'est jamais renvoye a la vue : seul son etat (definie / non
     * definie, date de mise a jour) est affiche.
     */
    public function ai(Organization $organization): View
    {
        $setting = $organization->aiSetting;

        $monthStart = now()->startOfMonth();
        $monthlyCost = (float) AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $monthStart->copy()->addMonth())
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        return view('admin.org.ai', [
            'organization' => $organization,
            'setting' => $setting,
            'providers' => ProviderResolver::ALLOWED_PROVIDERS,
            'monthlyCost' => $monthlyCost,
            'defaultModel' => (string) (config('ai.default_model') ?: config('ai.openrouter.model', 'openai/gpt-4o-mini')),
        ]);
    }

    public function updateAi(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(ProviderResolver::ALLOWED_PROVIDERS)],
            'model' => ['required', 'string', 'max:150'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_api_key' => ['nullable', 'boolean'],
            'monthly_budget_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $setting = OrganizationAiSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        $setting->provider = $data['provider'];
        $setting->model = trim($data['model']);
        $budget = $data['monthly_budget_usd'] ?? null;
        $setting->monthly_budget_usd = $budget !== null && $budget !== '' ? (float) $budget : null;
        $setting->is_enabled = $request->boolean('is_enabled');

        // Le champ cle est ecrit-seul : vide = conserver, case cochee = effacer.
        if ($request->boolean('clear_api_key')) {
            $setting->api_key = null;
            $setting->api_key_updated_at = null;
        } elseif (trim((string) ($data['api_key'] ?? '')) !== '') {
            $setting->api_key = trim($data['api_key']);
            $setting->api_key_updated_at = now();
        }

        $setting->save();

        return redirect()->route('organization.admin.ai', ['organization' => $organization->slug])
            ->with('success', __('admin.organization_ai_saved'));
    }

    public function identity(Organization $organization): View
    {
        return view('admin.org.identity', [
            'organization' => $organization,
        ]);
    }

    public function updateIdentity(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);

        if ($request->boolean('remove_logo') && $organization->logo_path) {
            $this->deleteLogoFile($organization->logo_path);
            $organization->update(['logo_path' => null]);

            return redirect()->route('organization.admin.identity', [
                'organization' => $organization->slug,
            ])->with('success', __('admin.organization_logo_removed'));
        }

        if ($request->hasFile('logo')) {
            if ($organization->logo_path) {
                $this->deleteLogoFile($organization->logo_path);
            }

            $filename = Str::random(32).'.'.$request->file('logo')->extension();
            $path = $request->file('logo')->storeAs(
                'organization-logos/'.$organization->id,
                $filename,
                'public',
            );

            $organization->update(['logo_path' => $path]);

            return redirect()->route('organization.admin.identity', [
                'organization' => $organization->slug,
            ])->with('success', __('admin.organization_logo_updated'));
        }

        return redirect()->route('organization.admin.identity', [
            'organization' => $organization->slug,
        ])->with('info', __('admin.organization_logo_no_change'));
    }

    private function deleteLogoFile(string $path): void
    {
        if (str_starts_with($path, 'organization-logos/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function aiSupervision(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_ai_supervision'));
    }

    public function memberAiProfiles(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_member_ai_profiles'));
    }

    public function aiInteractions(Organization $organization): View
    {
        return $this->comingSoon($organization, __('navigation.org_admin_ai_interactions'));
    }

    /**
     * Console RAG read-only (TASK-1217) : ce que l'IA connait des Dossiers de
     * CETTE Organization, et si l'index est coherent.
     *
     * Le read model borne tout par `organization_id`. Ce qu'il ne decide pas,
     * et qui se decide ici : le droit d'OUVRIR une source. Etre admin
     * d'Organization ne donne aucun privilege sur `DossierPolicy` (verifie :
     * `admin_id` n'y apparait pas) — un admin peut donc legitimement voir
     * qu'un Dossier prive contient des connaissances indexees sans pouvoir en
     * lire le contenu. « Portee != sujet » : on expose l'etat, jamais le
     * contenu, et le lien n'apparait que si la policy l'autorise vraiment.
     */
    /**
     * Hub « IA & connaissances » (TASK-1223) : l'etat du systeme IA de
     * l'Organization en une page — configuration, comportement, connaissances,
     * consommation — avec des liens vers les consoles existantes. Read-only,
     * vocabulaire humain, aucune cle affichee, « — » pour l'inconnu.
     */
    public function aiCockpit(Organization $organization, OrganizationRagOverview $overview, OrganizationAiEconomicUsage $economics): View
    {
        $setting = OrganizationAiSetting::query()
            ->where('organization_id', $organization->id)
            ->first();

        $registry = app(CapabilityRegistry::class);
        $capabilityIds = [
            CapabilityRegistry::CLARIFY_HELP_REQUEST,
            CapabilityRegistry::LOOP_SUMMARY,
            CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
        ];

        // Un prompt est « actif » pour la capability si un AdminAiPrompt actif
        // existe sur son scenario — la cascade summarize inclut ses variantes
        // localisees, exactement comme la resolution reelle (TASK-1221).
        $activeScenarios = AdminAiPrompt::query()
            ->where('is_active', true)
            ->pluck('scenario_id')
            ->all();

        $capabilities = array_map(static function (string $id) use ($registry, $activeScenarios): array {
            $definition = $registry->get($id);
            $promptActive = match ($id) {
                CapabilityRegistry::LOOP_SUMMARY => (bool) array_intersect(
                    ['chatloop_ai_summarize_fr', 'chatloop_ai_summarize_en', 'chatloop_ai_summarize'],
                    $activeScenarios,
                ),
                default => in_array($definition->promptKey, $activeScenarios, true),
            };

            return [
                'id' => $definition->id,
                'human_validation' => $definition->requiresHumanConfirmation,
                'read_only' => ! $definition->canWrite,
                'sources' => $definition->allowedSources,
                'prompt_active' => $promptActive,
            ];
        }, $capabilityIds);

        $monthStart = CarbonImmutable::now()->startOfMonth();

        return view('admin.org.ai-cockpit', [
            'organization' => $organization,
            'setting' => $setting,
            'ready' => $setting?->isUsable() ?? false,
            'constitutionVersion' => Constitution::VERSION,
            'capabilities' => $capabilities,
            // TASK-1227 : la doctrine active de l'Organization, ou aucune.
            'doctrine' => OrganizationAiDoctrine::activeFor((string) $organization->id),
            'rag' => $overview->summary($organization->id),
            'economics' => $economics->summary((string) $organization->id, $monthStart, $monthStart->addMonth()),
            'monthlyBudgetUsd' => $setting?->monthly_budget_usd,
        ]);
    }

    /**
     * Page « Comportement IA » (TASK-1227) : la Constitution BouclePro en
     * lecture seule, la doctrine de l'Organization (editable, versionnee),
     * les capabilities qui la suivent, la couverture du systeme nerveux et
     * le bac a sable « tester sans publier ». Tout est borne a CETTE
     * Organization ; aucune cle n'apparait.
     */
    public function aiBehavior(Organization $organization, NervousSystemCoverage $coverage): View
    {
        return view('admin.org.ai-behavior', $this->aiBehaviorViewData($organization, $coverage));
    }

    /**
     * @return array<string, mixed>
     */
    private function aiBehaviorViewData(Organization $organization, NervousSystemCoverage $coverage): array
    {
        $active = OrganizationAiDoctrine::activeFor((string) $organization->id);

        $history = OrganizationAiDoctrine::query()
            ->where('organization_id', $organization->id)
            ->with('author:id,name')
            ->orderByDesc('version')
            ->limit(20)
            ->get();

        return [
            'organization' => $organization,
            'constitutionVersion' => Constitution::VERSION,
            'constitutionText' => app(Constitution::class)->text(),
            'doctrine' => $active,
            'doctrineHistory' => $history,
            'doctrineHistoryTotal' => OrganizationAiDoctrine::query()->where('organization_id', $organization->id)->count(),
            'doctrineMaxChars' => OrganizationAiDoctrine::maxChars(),
            'coveredCapabilities' => $coverage->covered(),
            'inheritedFunctions' => $coverage->inherited(),
            'coveredCount' => $coverage->coveredCount(),
            'totalCount' => $coverage->totalCount(),
            'sandboxCapabilities' => OrganizationDoctrineSandbox::SUPPORTED,
            // Le resultat flashe n'est rendu que pour l'Organization qui l'a
            // produit (un admin de plusieurs Organizations ne le voit jamais
            // ailleurs — revue PASS A).
            'sandboxResult' => $this->sandboxResultFor($organization),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sandboxResultFor(Organization $organization): ?array
    {
        $result = session('doctrine_sandbox');

        if (! is_array($result) || ($result['organization_id'] ?? null) !== (string) $organization->id) {
            return null;
        }

        return $result;
    }

    /**
     * Enregistre une NOUVELLE version de la doctrine et l'active. Un texte
     * identique a la version active ne cree rien.
     */
    public function updateAiDoctrine(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.OrganizationAiDoctrine::maxChars()],
        ]);

        $before = OrganizationAiDoctrine::activeFor((string) $organization->id);
        $doctrine = OrganizationAiDoctrine::activate($organization, $data['body'], $request->user());

        $message = $before !== null && $before->is($doctrine)
            ? __('ai.behavior_doctrine_unchanged', ['version' => $doctrine->version])
            : __('ai.behavior_doctrine_saved', ['version' => $doctrine->version]);

        return redirect()
            ->route('organization.admin.ai-behavior', ['organization' => $organization->slug])
            ->with('success', $message);
    }

    /**
     * Retire la doctrine active : l'Organization revient a la composition
     * sans doctrine (identique a l'avant-TASK). L'historique reste.
     */
    public function withdrawAiDoctrine(Organization $organization): RedirectResponse
    {
        $withdrawn = OrganizationAiDoctrine::withdraw($organization);

        return redirect()
            ->route('organization.admin.ai-behavior', ['organization' => $organization->slug])
            ->with($withdrawn ? 'success' : 'info', $withdrawn
                ? __('ai.behavior_doctrine_withdrawn')
                : __('ai.behavior_doctrine_nothing_to_withdraw'));
    }

    /**
     * « Tester sans publier » : appel IA REEL avec la doctrine candidate,
     * comptabilise au ledger ; rien n'est active, aucune action metier.
     * PRG : le resultat voyage en session, le brouillon revient par old().
     */
    public function sandboxAiDoctrine(
        Request $request,
        Organization $organization,
        OrganizationDoctrineSandbox $sandbox,
    ): RedirectResponse {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.OrganizationAiDoctrine::maxChars()],
            'capability' => ['required', 'string', Rule::in(OrganizationDoctrineSandbox::SUPPORTED)],
            'question' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $result = $sandbox->run(
            $organization,
            $request->user(),
            $data['capability'],
            (string) ($data['body'] ?? ''),
            $data['question'],
        );

        return redirect()
            ->route('organization.admin.ai-behavior', ['organization' => $organization->slug])
            ->withInput($request->only(['body', 'capability', 'question']))
            ->with('doctrine_sandbox', $result->toArray());
    }

    /**
     * Observatoire des connaissances (TASK-1217 console, TASK-1226 vivant),
     * read-only : la page complete.
     */
    public function aiKnowledge(Organization $organization, OrganizationRagOverview $overview): View
    {
        return view('admin.org.ai-knowledge', $this->knowledgeObservatory($organization, $overview) + [
            'liveUrl' => route('organization.admin.ai-knowledge.live', ['organization' => $organization->slug]),
        ]);
    }

    /**
     * TASK-1226 : le fragment rafraichi par l'Observatoire (polling leger).
     *
     * Meme middleware, meme read model, meme partiel Blade que la page — une
     * seule source de rendu, donc jamais deux verites. Read-only strict :
     * aucun embedding, aucun appel provider, aucune lecture de fichier ; lire
     * l'Observatoire coute 0 appel IA. `no-store` : un poll ne se met jamais
     * en cache, ni cote navigateur ni cote proxy.
     */
    public function aiKnowledgeLive(Organization $organization, OrganizationRagOverview $overview): Response
    {
        $html = view('admin.org.partials.ai-knowledge-live', $this->knowledgeObservatory($organization, $overview))->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, no-store, private');
    }

    /**
     * Les donnees de l'Observatoire, partagees entre la page et le fragment.
     *
     * Le lien « Ouvrir » suit la `DossierPolicy` et elle seule : etre admin
     * ne donne pas acces au contenu d'un Dossier prive (TASK-1217). La policy
     * n'est evaluee que pour les Dossiers qui portent au moins une source, et
     * ses relations (`parent`, `loop`, `sharedWithLoop`) sont pre-attachees
     * depuis les Dossiers deja charges : `governingDossier()` et
     * `sharingAnchorIds()` remontent l'arbre en memoire, sans requete par
     * niveau. Le nombre de requetes ne depend donc pas du nombre de sources.
     *
     * @return array<string, mixed>
     */
    private function knowledgeObservatory(Organization $organization, OrganizationRagOverview $overview): array
    {
        $user = auth()->user();
        $sources = $overview->sources($organization->id);

        $dossiers = Dossier::query()
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $loopIds = $dossiers
            ->flatMap(fn (Dossier $dossier): array => [$dossier->loop_id, $dossier->shared_with_loop_id])
            ->filter()
            ->unique()
            ->values();

        $loops = $loopIds->isEmpty()
            ? collect()
            : Loop::query()->where('organization_id', $organization->id)->whereIn('id', $loopIds->all())->get()->keyBy('id');

        foreach ($dossiers as $dossier) {
            $dossier->setRelation('parent', $dossier->parent_id !== null ? $dossiers->get($dossier->parent_id) : null);
            $dossier->setRelation('loop', $dossier->loop_id !== null ? $loops->get($dossier->loop_id) : null);
            $dossier->setRelation('sharedWithLoop', $dossier->shared_with_loop_id !== null ? $loops->get($dossier->shared_with_loop_id) : null);
        }

        $referencedDossierIds = array_unique(array_column($sources, 'dossier_id'));
        $gate = $user !== null ? Gate::forUser($user) : null;
        $openable = [];

        foreach ($referencedDossierIds as $dossierId) {
            $dossier = $dossiers->get($dossierId);

            // Un Dossier dont la racine gouvernante ne se resout pas dans le
            // perimetre (parent supprime, hors Organization, ou chaine plus
            // profonde que la borne) n'est jamais ouvrable d'ici : sa
            // `visibility` n'est qu'une copie faite a la creation, la
            // policy ne peut pas y lire une autorisation fiable.
            $openable[$dossierId] = $gate !== null
                && $dossier !== null
                && $this->governingRootIsResolved($dossier)
                && $gate->allows('view', $dossier);
        }

        $sources = array_map(function (array $source) use ($openable): array {
            $source['can_open'] = $openable[$source['dossier_id']] ?? false;

            return $source;
        }, $sources);

        return [
            'organization' => $organization,
            'summary' => $overview->summary($organization->id),
            'sources' => $sources,
            'perimeters' => $overview->perimeters($sources),
            'availability' => $overview->indexingAvailability($organization),
            'diagnostics' => $overview->diagnostics($organization->id),
            'generatedAt' => CarbonImmutable::now(),
        ];
    }

    /**
     * Vrai si la remontee `parent` (pre-attachee depuis le perimetre de
     * l'Organization) atteint une vraie racine en moins de `Dossier::MAX_DEPTH`
     * niveaux — c'est-a-dire si `governingDossier()` repond sur une donnee
     * complete et non sur un enfant orphelin.
     */
    private function governingRootIsResolved(Dossier $dossier): bool
    {
        $current = $dossier;
        $depth = 0;

        while ($current->parent_id !== null && $depth < Dossier::MAX_DEPTH) {
            $parent = $current->relationLoaded('parent') ? $current->parent : null;

            if ($parent === null) {
                return false;
            }

            $current = $parent;
            $depth++;
        }

        return $current->parent_id === null;
    }

    /**
     * Console « Consommation IA » (TASK-1219), read-only.
     *
     * Rend ce que la garde economique compte deja pour CETTE Organization :
     * meme table, meme fenetre, meme forme en deux parts (cout connu d'un cote,
     * appels non mesurables de l'autre). Le read model porte le tenant et la
     * doctrine ; la page ne fait que choisir la periode et les dimensions.
     *
     * Le budget mensuel eventuel est lu depuis `organization_ai_settings` pour
     * situer le cout connu — jamais pour completer un cout manquant.
     */
    public function aiConsumption(
        Request $request,
        Organization $organization,
        OrganizationAiConsumption $consumption,
        OrganizationAiEconomicUsage $usage,
    ): View {
        $filters = AiConsumptionFilters::fromRequest($request);
        $month = AiConsumptionFilters::currentMonth();

        // TASK-1228 : le budget mensuel n'a de sens que sur la fenetre de la
        // garde ; sur une periode personnalisee, « consomme » reste vrai mais
        // « reste » n'est pas calcule.
        $isCurrentMonth = $filters->from->equalTo($month->from) && $filters->to->equalTo($month->to);
        $economics = $usage->summary((string) $organization->id, $filters->from, $filters->to);
        $budget = $organization->aiSetting?->monthly_budget_usd;
        $budget = $budget !== null ? (float) $budget : null;

        return view('admin.org.ai-consumption', [
            'organization' => $organization,
            'filters' => $filters,
            'isCurrentMonth' => $isCurrentMonth,
            'economics' => $economics,
            'economicsByUser' => $usage->byUser((string) $organization->id, $filters->from, $filters->to),
            'budget' => [
                'monthly_usd' => $budget,
                'consumed_usd' => $economics['total_known_cost_usd'],
                // Reste = budget - CONNU ; les inconnus sont comptes a cote,
                // jamais soustraits ni supposes nuls.
                'remaining_usd' => $isCurrentMonth && $budget !== null
                    ? $budget - (float) ($economics['total_known_cost_usd'] ?? 0.0)
                    : null,
                'percent' => $isCurrentMonth && $budget !== null && $budget > 0 && $economics['total_known_cost_usd'] !== null
                    ? min(100.0, round($economics['total_known_cost_usd'] / $budget * 100, 1))
                    : null,
            ],
            'summary' => $consumption->summary($organization->id, $filters),
            'byProcess' => $consumption->byProcess($organization->id, $filters),
            'byModel' => $consumption->byModel($organization->id, $filters),
            'byProvider' => $consumption->byProvider($organization->id, $filters),
            'byUser' => $consumption->byUser($organization->id, $filters),
            'byDay' => $consumption->byDay($organization->id, $filters),
            'available' => $consumption->availableFilters($organization->id, $filters),
            'monthlyBudgetUsd' => $organization->aiSetting?->monthly_budget_usd,
        ]);
    }

    // ── Design / Homepage ───────────────────────────────────────────────────────

    public function homepage(Organization $organization): View
    {
        return view('admin.org.homepage', compact('organization'));
    }

    public function updateHomepage(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'homepage_template' => ['nullable', 'string', Rule::in(['default', 'bouclepro_hero_v2', 'artscilab_hero'])],
            'subheadline' => ['nullable', 'string', 'max:500'],
            'card_create_label' => ['nullable', 'string', 'max:100'],
            'card_meet_label' => ['nullable', 'string', 'max:100'],
            'card_help_label' => ['nullable', 'string', 'max:100'],
            'card_offer_label' => ['nullable', 'string', 'max:100'],
            'ai_note' => ['nullable', 'string', 'max:255'],
            'primary_cta_label' => ['nullable', 'string', 'max:100'],
            'primary_cta_url' => ['nullable', 'string', 'max:500'],
            'secondary_cta_label' => ['nullable', 'string', 'max:100'],
            'secondary_cta_url' => ['nullable', 'string', 'max:500'],
            'headline_solid' => ['nullable', 'string', 'max:100'],
            'headline_outline' => ['nullable', 'string', 'max:200'],
            'card_1_label' => ['nullable', 'string', 'max:100'],
            'card_2_label' => ['nullable', 'string', 'max:100'],
            'card_3_label' => ['nullable', 'string', 'max:100'],
            'card_4_label' => ['nullable', 'string', 'max:100'],
        ]);

        foreach (['primary_cta_url', 'secondary_cta_url'] as $urlField) {
            if (! empty($validated[$urlField]) && ! $this->isSafeHomepageUrl($validated[$urlField])) {
                return back()->withErrors([$urlField => 'URL invalide. Utilisez une URL interne relative ou une URL HTTPS.'])->withInput();
            }
        }

        $template = $validated['homepage_template'] ?? null;

        $settings = [];
        foreach (['subheadline', 'card_create_label', 'card_meet_label', 'card_help_label', 'card_offer_label', 'ai_note', 'primary_cta_label', 'primary_cta_url', 'secondary_cta_label', 'secondary_cta_url', 'headline_solid', 'headline_outline', 'card_1_label', 'card_2_label', 'card_3_label', 'card_4_label'] as $field) {
            if (filled($validated[$field] ?? null)) {
                $settings[$field] = $validated[$field];
            }
        }

        $organization->update([
            'homepage_template' => $template,
            'homepage_settings' => ! empty($settings) ? $settings : null,
        ]);

        return redirect()->route('organization.admin.homepage', $organization)
            ->with('success', 'Page d\'accueil mise à jour.');
    }

    private function isSafeHomepageUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    // ── Design / Themes ─────────────────────────────────────────────────────────

    public function themes(Request $request, Organization $organization): View
    {
        $mainOrgId = Organization::orderBy('created_at')->value('id');
        $themes = Theme::with('organization')
            ->whereIn('organization_id', [$organization->id, $mainOrgId])
            ->orderBy('is_default', 'desc')
            ->orderBy('label')
            ->get();

        $currentTheme = null;
        if ($request->filled('theme')) {
            $currentTheme = $themes->firstWhere('key', $request->theme);
        }
        if (! $currentTheme) {
            $currentTheme = $themes->firstWhere('is_default', true) ?? $themes->first();
        }

        $themeKeys = $themes->pluck('key')->values()->all();
        $currentIndex = array_search($currentTheme->key, $themeKeys);
        $prevTheme = $currentIndex > 0 ? $themes[$currentIndex - 1] : null;
        $nextTheme = $currentIndex < count($themeKeys) - 1 ? $themes[$currentIndex + 1] : null;

        return view('admin.org.themes.index', compact('organization', 'themes', 'currentTheme', 'prevTheme', 'nextTheme'));
    }

    public function themesCreate(Organization $organization): View
    {
        return view('admin.org.themes.create', compact('organization'));
    }

    public function themesStore(Request $request, Organization $organization): RedirectResponse
    {
        if ($request->has('tokens') && is_string($request->tokens)) {
            $request->merge(['tokens' => json_decode($request->tokens, true) ?? []]);
        }
        if ($request->has('dark_tokens') && is_string($request->dark_tokens)) {
            $request->merge(['dark_tokens' => json_decode($request->dark_tokens, true) ?? []]);
        }

        $data = $request->validate([
            'key' => 'required|string|max:50|unique:themes,key',
            'label' => 'required|string|max:100',
            'description' => 'nullable|string',
            'tokens' => ['required', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'dark_tokens' => ['nullable', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token sombre « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
        ]);

        $data['organization_id'] = $organization->id;
        $data['is_default'] = false;
        $data['dark_tokens'] = $data['dark_tokens'] ?? [];

        $theme = Theme::create($data);
        Theme::regenerateCache();

        return redirect()->route('organization.admin.themes', [$organization, 'theme' => $theme->key])
            ->with('success', 'Thème « '.$theme->label.' » créé.');
    }

    public function themesEdit(Organization $organization, Theme $theme): View
    {
        abort_if($theme->organization_id !== $organization->id, 403, 'Vous ne pouvez modifier que vos propres thèmes.');

        return view('admin.org.themes.edit', compact('organization', 'theme'));
    }

    public function themesUpdate(Request $request, Organization $organization, Theme $theme): RedirectResponse
    {
        abort_if($theme->organization_id !== $organization->id, 403, 'Vous ne pouvez modifier que vos propres thèmes.');

        if ($request->has('tokens') && is_string($request->tokens)) {
            $request->merge(['tokens' => json_decode($request->tokens, true) ?? []]);
        }
        if ($request->has('dark_tokens') && is_string($request->dark_tokens)) {
            $request->merge(['dark_tokens' => json_decode($request->dark_tokens, true) ?? []]);
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', Rule::unique('themes', 'key')->ignore($theme->id)],
            'label' => 'required|string|max:100',
            'description' => 'nullable|string',
            'tokens' => ['required', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
            'dark_tokens' => ['nullable', 'array', function ($attribute, $value, $fail) {
                foreach ($value as $key => $color) {
                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                        $fail("Le token sombre « {$key} » doit être une couleur hexadécimale valide.");
                    }
                }
            }],
        ]);
        $data['dark_tokens'] = $data['dark_tokens'] ?? [];

        $theme->update($data);
        Theme::regenerateCache();

        return redirect()->route('organization.admin.themes', [$organization, 'theme' => $theme->key])
            ->with('success', 'Thème « '.$theme->label.' » mis à jour.');
    }

    public function themesDestroy(Organization $organization, Theme $theme): RedirectResponse
    {
        abort_if($theme->organization_id !== $organization->id, 403, 'Vous ne pouvez supprimer que vos propres thèmes.');

        if ($theme->is_default) {
            return back()->with('error', 'Impossible de supprimer le thème par défaut.');
        }

        Organization::where('theme_id', $theme->id)->update(['theme_id' => null]);

        $theme->delete();
        Theme::regenerateCache();

        return redirect()->route('organization.admin.themes', [$organization])
            ->with('success', 'Thème « '.$theme->label.' » supprimé.');
    }

    public function themesAssign(Organization $organization, Theme $theme): RedirectResponse
    {
        $mainOrgId = Organization::orderBy('created_at')->value('id');
        abort_if(
            $theme->organization_id !== $organization->id && $theme->organization_id !== $mainOrgId,
            403,
            'Ce thème ne peut pas être sélectionné pour cette organisation.'
        );

        $organization->update(['theme_id' => $theme->id]);

        return redirect()->route('organization.admin.themes', [$organization, 'theme' => $theme->key])
            ->with('success', 'Thème « '.$theme->label.' » appliqué.');
    }

    // ── Login history ─────────────────────────────────────────────────────────

    public function loginHistory(Request $request, Organization $organization): View
    {
        $query = LoginLog::where('organization_id', $organization->id)
            ->with('user');

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'user' => $query->orderBy(
                User::select('name')->whereColumn('id', 'login_logs.user_id')->limit(1),
                $direction
            ),
            'ip_address' => $query->orderBy('ip_address', $direction),
            default => $query->latest('created_at'),
        };

        $loginLogs = $query->paginate(25)->withQueryString();

        return view('admin.org.login-history.index', [
            'organization' => $organization,
            'loginLogs' => $loginLogs,
        ]);
    }

    public function loginHistoryUser(Request $request, Organization $organization, User $user): View
    {
        abort_if($user->organization_id !== $organization->id, 404);

        $logs = LoginLog::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.org.login-history.user', [
            'organization' => $organization,
            'user' => $user,
            'logs' => $logs,
        ]);
    }

    public function systemEmailTemplates(Request $request, Organization $organization): View
    {
        $query = SystemEmailTemplate::with('organization')
            ->where('organization_id', $organization->id)
            ->orderBy('locale')
            ->orderBy('name');

        $locale = $request->input('locale', $organization->locale);

        if ($locale) {
            $query->where('locale', $locale);
        }

        $templates = $query->get();

        return view('admin.org.system-email-templates', [
            'organization' => $organization,
            'templates' => $templates,
            'currentLocale' => $locale,
        ]);
    }

    public function editSystemEmailTemplate(Organization $organization, SystemEmailTemplate $systemEmailTemplate): View
    {
        abort_if($systemEmailTemplate->organization_id !== $organization->id, 404);

        return view('admin.org.system-email-template-edit', [
            'organization' => $organization,
            'systemEmailTemplate' => $systemEmailTemplate,
        ]);
    }

    public function updateSystemEmailTemplate(Request $request, Organization $organization, SystemEmailTemplate $systemEmailTemplate): RedirectResponse
    {
        abort_if($systemEmailTemplate->organization_id !== $organization->id, 404);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'enabled' => 'boolean',
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        $systemEmailTemplate->update($validated);

        return redirect()->route('organization.admin.system-email-templates', $organization)
            ->with('success', __('admin.emailer_updated'));
    }

    private function comingSoon(Organization $organization, string $sectionName): View
    {
        return view('admin.org.coming-soon', [
            'organization' => $organization,
            'sectionName' => $sectionName,
        ]);
    }
}
