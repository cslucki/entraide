<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
        private readonly LoopMessageService $loopMessageService,
        private readonly ChatLoopAiService $chatLoopAiService,
        private readonly AiProvider $aiProvider,
    ) {}

    private function resolveOrganization(): Organization
    {
        $organization = CurrentOrganization::get();

        if ($organization) {
            assert($organization instanceof Organization);

            return $organization;
        }

        $user = auth()->user();

        if (! $user->organization) {
            abort(404);
        }

        assert($user->organization instanceof Organization);

        return $user->organization;
    }

    private function assertUserBelongsToOrganization(Organization $organization): void
    {
        $user = auth()->user();

        $orgId = $user->organization_id;
        if ($orgId !== $organization->id) {
            abort(404);
        }
    }

    private function resolveOrganizationId(): string
    {
        $organizationId = CurrentOrganization::id();

        if ($organizationId) {
            return $organizationId;
        }

        $user = auth()->user();

        if ($orgId = $user->organization_id) {
            return $orgId;
        }

        abort(403);
    }

    private function loopRoute(string $route, Loop $loop): string
    {
        $organization = request()->route('organization');

        if ($organization && request()->routeIs('organization.*')) {
            return route('organization.'.$route, [
                'organization' => $organization,
                'loop' => $loop,
            ]);
        }

        return route($route, $loop);
    }

    private function resolveRouteLoop(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): Loop
    {
        if ($loopOrOrganization instanceof Loop) {
            return $loopOrOrganization;
        }

        if ($loop instanceof Loop) {
            return $loop;
        }

        abort(404);
    }

    public function index(): View|RedirectResponse
    {
        $organizationId = $this->resolveOrganizationId();
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        $user = auth()->user();

        // Mono-loop mode: redirect to primary loop if defined
        if ($organization->isMonoLoop()) {
            if ($organization->primary_loop_id) {
                $primaryLoop = $organization->primaryLoop;

                if ($primaryLoop && $primaryLoop->organization_id === $organization->id) {
                    return redirect($this->loopRoute('loops.show', $primaryLoop));
                }
            }

            return view('loops.index', [
                'loops' => collect(),
                'canCreate' => false,
                'noPrimaryLoopWarning' => true,
            ]);
        }

        // Multi-loop mode: show list, redirect if single accessible loop
        $loops = $this->getAccessibleLoopsQuery($organizationId, $user)->get();

        if ($loops->count() === 1) {
            return redirect($this->loopRoute('loops.show', $loops->first()));
        }

        $canCreate = $user->can('create', [Loop::class, $organization]);

        // Only the domains actually used by the listed Loops: a filter offering
        // empty options would be noise on a ~200-people Organization.
        $filterDomains = $loops->pluck('categories')->flatten()->unique('id')
            ->sortBy(fn ($c) => $c->displayName('loops'))->values();

        return view('loops.index', compact('loops', 'filterDomains'))->with('canCreate', $canCreate);
    }

    /**
     * The catalog: every active Loop of the Organization is discoverable,
     * whether the user is a member or not (TASK-1075 — "privée" no longer
     * means "hidden", it means "content locked to members"). Membership and
     * pending-request state are annotated via correlated EXISTS subqueries
     * so the view can render the right CTA without N+1 queries.
     */
    private function getAccessibleLoopsQuery(string $organizationId, $user)
    {
        return Loop::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->with(['owner.user', 'categories'])
            ->withCount('activeMembers')
            ->withMax('messages as last_message_at', 'created_at')
            ->withExists(['members as is_member' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'active');
            }])
            ->withExists(['members as is_owner' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'active')->where('role', 'owner');
            }])
            ->withExists(['joinRequests as has_pending_request' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', LoopJoinRequest::STATUS_PENDING);
            }])
            ->latest('updated_at');
    }

    public function create(): View
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);
        $this->authorize('create', [Loop::class, $organization]);

        return view('loops.create', ['domains' => $this->organizationDomains($organization)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);
        $this->authorize('create', [Loop::class, $organization]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:140',
            'description' => 'nullable|string|max:5000',
            'access_mode' => ['nullable', Rule::in(Loop::ACCESS_MODES)],
            'cover_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'category_ids' => 'nullable|array|max:'.Loop::MAX_DOMAINS,
            'category_ids.*' => 'string',
        ]);

        $loop = $this->loopService->createLoop(
            $request->user(),
            $data['name'],
            $data['description'] ?? null,
            'private',
            $data['tagline'] ?? null,
            $data['access_mode'] ?? Loop::ACCESS_REQUEST,
        );

        $loop->categories()->sync($this->resolveDomainIds($organization, $data['category_ids'] ?? []));

        if ($request->hasFile('cover_image')) {
            $loop->update(['cover_image_path' => $this->storeCoverImage($request, $loop)]);
        }

        // TASK-1076: optional post-creation step. Never blocking — the screen
        // offers "Plus tard" and "Ouvrir la Boucle".
        return redirect($this->loopRoute('loops.invite', $loop))
            ->with('success', __('loops.created_success'));
    }

    public function edit(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $loop);

        $loop->load('categories');

        return view('loops.edit', [
            'loop' => $loop,
            'domains' => $this->organizationDomains($organization),
        ]);
    }

    public function update(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $loop);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tagline' => 'nullable|string|max:140',
            'description' => 'nullable|string|max:5000',
            'access_mode' => ['nullable', Rule::in(Loop::ACCESS_MODES)],
            'cover_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_cover_image' => 'nullable|boolean',
            'category_ids' => 'nullable|array|max:'.Loop::MAX_DOMAINS,
            'category_ids.*' => 'string',
        ]);

        if ($request->boolean('remove_cover_image') && $loop->cover_image_path) {
            $this->deleteCoverImage($loop->cover_image_path);
            $data['cover_image_path'] = null;
        } elseif ($request->hasFile('cover_image')) {
            $data['cover_image_path'] = $this->storeCoverImage($request, $loop);
        }

        $loop->categories()->sync($this->resolveDomainIds($organization, $data['category_ids'] ?? []));

        unset($data['cover_image'], $data['remove_cover_image'], $data['category_ids']);

        $this->loopService->updateLoop($loop, $data);

        // TASK-1076: editing is started from the catalog, so we come back to it
        // (with ?updated=<id> to highlight the card) instead of dropping the user
        // into the workspace. The AdminLoopController flow is untouched.
        return redirect($this->loopsIndexRoute(['updated' => $loop->id]))
            ->with('success', __('loops.catalog_updated'));
    }

    /**
     * Optional "invite people" step shown right after a Loop is created.
     * Restricted to whoever may manage the Loop (owner / Organization admin) —
     * a standard member never reaches it.
     */
    public function invite(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('manageJoinRequests', $loop);

        return view('loops.invite', [
            'loop' => $loop,
            'candidates' => $this->invitableOrganizationMembers($loop, $organization),
        ]);
    }

    public function storeMembers(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('manageJoinRequests', $loop);

        $data = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'string',
        ]);

        // Re-scope server-side: only active users of THIS Organization, whatever
        // the form posted.
        $userIds = $this->invitableOrganizationMembers($loop, $organization)
            ->whereIn('id', $data['user_ids'])
            ->pluck('id')
            ->all();

        $result = $this->loopService->addMembersFromOrganization($loop, $userIds, $request->user());

        return redirect($this->loopRoute('loops.invite', $loop))
            ->with('success', trans_choice('loops.invite_members_added', $result['added'], ['count' => $result['added']]));
    }

    /** Active Organization members who are not active members of this Loop yet. */
    private function invitableOrganizationMembers(Loop $loop, Organization $organization)
    {
        $alreadyIn = LoopMember::where('loop_id', $loop->id)
            ->where('status', 'active')
            ->pluck('user_id');

        return User::assignable()
            ->where('organization_id', $organization->id)
            ->whereNotIn('id', $alreadyIn)
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'email']);
    }

    /** Domains (Annuaire referential) selectable for this Organization. */
    private function organizationDomains(Organization $organization)
    {
        return Category::where('organization_id', $organization->id)
            ->orderBy('name_b2c')
            ->get();
    }

    /**
     * Keep only domain ids that really belong to this Organization, capped at
     * MAX_DOMAINS. Tenant scoping is enforced here, server-side: a forged
     * category_ids payload pointing at another Organization is silently dropped
     * rather than trusted from the form.
     */
    private function resolveDomainIds(Organization $organization, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Category::where('organization_id', $organization->id)
            ->whereIn('id', $ids)
            ->limit(Loop::MAX_DOMAINS)
            ->pluck('id')
            ->all();
    }

    /**
     * URL of the Loop catalog, preserving the organization-prefixed route group
     * when the current request is inside it.
     */
    private function loopsIndexRoute(array $query = []): string
    {
        $organization = request()->route('organization');

        if ($organization && request()->routeIs('organization.*') && Route::has('organization.loops.index')) {
            return route('organization.loops.index', array_merge(['organization' => $organization], $query));
        }

        return route('loops.index', $query);
    }

    private function storeCoverImage(Request $request, Loop $loop): string
    {
        if ($loop->cover_image_path) {
            $this->deleteCoverImage($loop->cover_image_path);
        }

        $filename = Str::random(32).'.'.$request->file('cover_image')->extension();

        return $request->file('cover_image')->storeAs(
            'loop-covers/'.$loop->id,
            $filename,
            'public',
        );
    }

    private function deleteCoverImage(string $path): void
    {
        if (str_starts_with($path, 'loop-covers/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function show(Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): View
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = auth()->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        $isPrimaryLoop = $organization->primary_loop_id === $loop->id;

        if (! $isMember && ! $isPrimaryLoop) {
            // TASK-1075: "privée" no longer means hidden from non-members —
            // it means the workspace content is locked. Every non-member
            // sees the presentation card instead of a hard 404, except for
            // archived Loops which are never independently discoverable.
            if (! $loop->isActive()) {
                abort(404);
            }

            return $this->showPresentation($loop, $user);
        }

        $loop->load(['members.user']);

        $eligibleReferrals = $this->loopService->getEligibleReferrals($user, $loop);

        $clarificationEnabled = AiConfig::get('clarification_enabled', false);

        $canManageJoinRequests = $user->can('manageJoinRequests', $loop);
        $pendingJoinRequests = $canManageJoinRequests
            ? LoopJoinRequest::where('loop_id', $loop->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->with('user')
                ->oldest()
                ->get()
            : collect();

        return view('loops.show', compact(
            'loop', 'eligibleReferrals', 'isMember', 'clarificationEnabled',
            'canManageJoinRequests', 'pendingJoinRequests',
        ));
    }

    private function showPresentation(Loop $loop, $user): View
    {
        $loop->loadCount('activeMembers');
        $loop->load(['owner.user', 'categories']);

        $pendingRequest = LoopJoinRequest::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->first();

        return view('loops.presentation', [
            'loop' => $loop,
            'pendingRequest' => $pendingRequest,
        ]);
    }

    public function join(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $alreadyActive = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($alreadyActive) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('info', __('loops.already_member'));
        }

        // access_mode=open required — defense in depth, the button is only
        // ever shown for open Loops, but a direct POST must be refused too.
        $this->authorize('join', $loop);

        $this->loopService->joinOpenLoop($loop, $user);

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', __('loops.joined'));
    }

    public function storeJoinRequest(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('requestToJoin', $loop);

        $data = $request->validate([
            'message' => 'nullable|string|max:500',
        ]);

        try {
            $this->loopService->requestToJoin($loop, $request->user(), $data['message'] ?? null);
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', __('loops.join_request_sent'));
    }

    public function cancelJoinRequest(Request $request, LoopJoinRequest $joinRequest): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($joinRequest->organization_id !== $organization->id) {
            abort(404);
        }

        if ($joinRequest->user_id !== $request->user()->id) {
            abort(404);
        }

        $loop = $joinRequest->loop;

        try {
            $this->loopService->cancelJoinRequest($joinRequest);
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('info', __('loops.join_request_cancelled'));
    }

    public function acceptJoinRequest(Request $request, LoopJoinRequest $joinRequest): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($joinRequest->organization_id !== $organization->id) {
            abort(404);
        }

        $loop = $joinRequest->loop;

        $this->authorize('manageJoinRequests', $loop);

        try {
            $this->loopService->acceptJoinRequest($joinRequest, $request->user());
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', __('loops.join_request_accepted'));
    }

    public function rejectJoinRequest(Request $request, LoopJoinRequest $joinRequest): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($joinRequest->organization_id !== $organization->id) {
            abort(404);
        }

        $loop = $joinRequest->loop;

        $this->authorize('manageJoinRequests', $loop);

        try {
            $this->loopService->rejectJoinRequest($joinRequest, $request->user());
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('info', __('loops.join_request_rejected'));
    }

    public function leave(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $member = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $member) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('info', __('loops.not_member'));
        }

        if ($member->role === 'owner') {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('error', __('loops.owner_cannot_leave'));
        }

        $member->update(['status' => 'left']);

        $orgSlug = request()->route('organization');
        $indexRoute = $orgSlug && Route::has('organization.loops.index')
            ? route('organization.loops.index', ['organization' => $orgSlug])
            : route('loops.index');

        return redirect($indexRoute)
            ->with('success', __('loops.left'));
    }

    public function analyzeHelpIntention(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(404);
        }

        $data = $request->validate([
            'intention' => 'required|string|min:3|max:2000',
        ]);

        $clarificationEnabled = AiConfig::get('clarification_enabled', false);

        if (! $clarificationEnabled) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('help_request_error', __('loops.clarification_disabled'));
        }

        $result = $this->aiProvider->analyze($data['intention']);

        if ($result->isBlocked()) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('help_request_error', $result->fallback['reason'] ?? 'Cette demande ne peut pas être publiée.');
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('help_request_analysis', $result->toArray())
            ->with('help_request_intention', $data['intention']);
    }

    public function publishHelpRequest(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(404);
        }

        $data = $request->validate([
            'title' => 'required|string|max:120',
            'need' => 'required|string|max:2000',
            'help_type' => 'required|string|in:request,service',
        ]);

        $route = $data['help_type'] === 'service'
            ? 'services.create'
            : 'requests.create';

        return redirect()->route($route)
            ->withInput([
                'title' => $data['title'],
                'description' => $data['need'],
            ]);
    }

    public function addMember(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        $currentMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $currentMember || ! in_array($currentMember->role, ['owner', 'moderator'])) {
            abort(404);
        }

        $data = $request->validate([
            'referral_id' => 'required|string|exists:referrals,id',
        ]);

        $referral = Referral::findOrFail($data['referral_id']);

        try {
            $this->loopService->addReferralToLoop($loop, $user, $referral);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', 'Membre ajouté à la boucle.');
    }

    public function storeMessage(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        try {
            $this->loopMessageService->sendUserMessage(
                $loop,
                $request->user(),
                $data['body'],
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', 'Message envoyé.');
    }

    public function askAi(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveRouteLoop($loopOrOrganization, $loop);
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'action' => 'required|string|in:answer,ask',
            'question' => 'required_if:action,ask|string|max:500',
        ]);

        if (! config('ai.chatloop.enabled', true)) {
            abort(404);
        }

        try {
            if ($data['action'] === 'ask') {
                $this->chatLoopAiService->ask($loop, $request->user(), trim((string) ($data['question'] ?? '')));
            } else {
                $this->chatLoopAiService->answer($loop, $request->user());
            }
        } catch (\RuntimeException $e) {
            return redirect($this->loopRoute('loops.show', $loop))
                ->with('error', $e->getMessage());
        }

        return redirect($this->loopRoute('loops.show', $loop))
            ->with('success', $data['action'] === 'ask' ? __('loops.ai_question_requested') : __('loops.ai_answer_requested'));
    }
}
