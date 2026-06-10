<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Referral;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
        private readonly LoopMessageService $loopMessageService,
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

            return view('loops.mono-setup-required');
        }

        // Multi-loop mode: show list, redirect if single accessible loop
        $loops = $this->getAccessibleLoopsQuery($organizationId, $user)->get();

        if ($loops->count() === 1) {
            return redirect($this->loopRoute('loops.show', $loops->first()));
        }

        return view('loops.index', compact('loops'))->with('canCreate', true);
    }

    private function getAccessibleLoopsQuery(string $organizationId, $user)
    {
        return Loop::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public')
                    ->orWhereIn('id', function ($sub) use ($user) {
                        $sub->select('loop_id')
                            ->from('loop_members')
                            ->where('user_id', $user->id);
                    });
            })
            ->withCount('activeMembers')
            ->withMax('messages as last_message_at', 'created_at')
            ->latest('updated_at');
    }

    public function create(): View
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        return view('loops.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->resolveOrganization();
        $this->assertUserBelongsToOrganization($organization);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        $loop = $this->loopService->createLoop(
            $request->user(),
            $data['name'],
            $data['description'] ?? null,
        );

        return redirect()->route('loops.show', $loop)
            ->with('success', 'Boucle créée avec succès.');
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

        if ($loop->isPrivate() && ! $isMember && ! $isPrimaryLoop) {
            abort(404);
        }

        $loop->load(['members.user']);

        $eligibleReferrals = $this->loopService->getEligibleReferrals($user, $loop);

        return view('loops.show', compact('loop', 'eligibleReferrals', 'isMember'));
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

        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                return redirect()->route('loops.show', $loop)
                    ->with('info', 'Vous êtes déjà membre de cette boucle.');
            }

            $existing->update(['status' => 'active', 'joined_at' => now()]);

            return redirect()->route('loops.show', $loop)
                ->with('success', 'Vous avez rejoint la boucle.');
        }

        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return redirect()->route('loops.show', $loop)
            ->with('success', 'Vous avez rejoint la boucle.');
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
            return redirect()->route('loops.show', $loop)
                ->with('info', 'Vous n\'êtes pas membre de cette boucle.');
        }

        if ($member->role === 'owner') {
            return redirect()->route('loops.show', $loop)
                ->with('error', 'Le propriétaire ne peut pas quitter la boucle.');
        }

        $member->update(['status' => 'left']);

        return redirect()->route('loops.index')
            ->with('success', 'Vous avez quitté la boucle.');
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

        $result = $this->aiProvider->analyze($data['intention']);

        if ($result->isBlocked()) {
            return redirect()->route('loops.show', $loop)
                ->with('help_request_error', $result->fallback['reason'] ?? 'Cette demande ne peut pas être publiée.');
        }

        return redirect()->route('loops.show', $loop)
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
            'context' => 'nullable|string|max:3000',
            'expected_help_type' => 'nullable|string|max:500',
            'deadline' => 'nullable|string|max:500',
            'urgency' => 'nullable|string|in:low,normal,high',
        ]);

        $body = $data['need'];
        $title = $data['title'];
        $need = $data['need'];
        $context = $data['context'] ?? '';
        $expectedHelpType = $data['expected_help_type'] ?? '';
        $deadline = $data['deadline'] ? ['label' => $data['deadline']] : null;
        $urgency = $data['urgency'] ?? 'normal';

        try {
            $this->loopMessageService->sendHelpRequestMessage(
                $loop,
                $user,
                $body,
                $title,
                $need,
                $context,
                $expectedHelpType,
                $deadline,
                $urgency,
            );
        } catch (\RuntimeException $e) {
            return redirect()->route('loops.show', $loop)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('loops.show', $loop)
            ->with('success', 'Votre demande d\'aide a été publiée dans la boucle.');
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

        return redirect()->route('loops.show', $loop)
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

        return redirect()->route('loops.show', $loop)
            ->with('success', 'Message envoyé.');
    }
}
