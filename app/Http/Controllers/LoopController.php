<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\Referral;
use App\Services\LoopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
    ) {}

    public function index(): View
    {
        $user = auth()->user();

        $loops = Loop::where('community_id', $user->community_id)
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
        return view('loops.create');
    }

    public function store(Request $request): RedirectResponse
    {
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
        $user = auth()->user();

        if ($loop->community_id !== $user->community_id) {
            abort(404);
        }

        $loop->load(['members.user']);

        $eligibleReferrals = $this->loopService->getEligibleReferrals($user, $loop);

        $isMember = $loop->members->contains('user_id', $user->id);

        return view('loops.show', compact('loop', 'eligibleReferrals', 'isMember'));
    }

    public function addMember(Request $request, Loop $loop): RedirectResponse
    {
        $user = $request->user();

        if ($loop->community_id !== $user->community_id) {
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
}
