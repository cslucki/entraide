<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Referral;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
        private readonly LoopMessageService $loopMessageService,
    ) {}

    private function resolveCommunity(): Community
    {
        if (app()->bound('current_community')) {
            return app('current_community');
        }

        $user = auth()->user();

        if (! $user->community) {
            abort(404);
        }

        return $user->community;
    }

    private function assertUserBelongsToCommunity(Community $community): void
    {
        $user = auth()->user();

        if ($user->community_id !== $community->id) {
            abort(404);
        }
    }

    public function index(): View
    {
        $community = $this->resolveCommunity();
        $this->assertUserBelongsToCommunity($community);

        $user = auth()->user();

        $loops = Loop::where('community_id', $community->id)
            ->whereIn('id', function ($q) use ($user) {
                $q->select('loop_id')
                    ->from('loop_members')
                    ->where('user_id', $user->id);
            })
            ->withCount('activeMembers')
            ->latest()
            ->get();

        return view('loops.index', compact('loops'));
    }

    public function create(): View
    {
        $community = $this->resolveCommunity();
        $this->assertUserBelongsToCommunity($community);

        return view('loops.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $community = $this->resolveCommunity();
        $this->assertUserBelongsToCommunity($community);

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

    public function show(Loop $loop): View
    {
        $community = $this->resolveCommunity();
        $this->assertUserBelongsToCommunity($community);

        if ($loop->community_id !== $community->id) {
            abort(404);
        }

        $user = auth()->user();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            abort(404);
        }

        $loop->load(['members.user']);

        $messages = $loop->messages()
            ->with('sender')
            ->oldest()
            ->get();

        $eligibleReferrals = $this->loopService->getEligibleReferrals($user, $loop);

        return view('loops.show', compact('loop', 'messages', 'eligibleReferrals', 'isMember'));
    }

    public function addMember(Request $request, Loop $loop): RedirectResponse
    {
        $community = $this->resolveCommunity();
        $this->assertUserBelongsToCommunity($community);

        if ($loop->community_id !== $community->id) {
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

    public function storeMessage(Request $request, Loop $loop): RedirectResponse
    {
        $community = $this->resolveCommunity();
        $this->assertUserBelongsToCommunity($community);

        if ($loop->community_id !== $community->id) {
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
