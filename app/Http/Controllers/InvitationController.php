<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\LoopInvitation;
use Illuminate\View\View;

class InvitationController extends Controller
{
    /**
     * Personal invitation tracking.
     *
     * Every query here is scoped to the signed-in user — this screen has always
     * been "my invitations", not an Organization-wide console. Loop invitations
     * follow the same rule through LoopInvitation::visibleTo(), which widens the
     * set for Loop owners and Organization admins without exposing one member's
     * invitations to another.
     */
    public function index(): View
    {
        $user = auth()->user();

        $referralPointsEarned = $user->referralRewards()->sum('points');
        $sentReferralsCount = $user->sentReferrals()->count();
        $activatedReferralsCount = $user->sentReferrals()->where('status', 'activated')->count();
        $referralLink = $user->organization?->slug && $user->referral_code
            ? route('organization.register', ['organization' => $user->organization->slug, 'ref' => $user->referral_code])
            : null;

        $sentInvitations = EmailLog::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('data->source', 'referral-invitation')
            ->orderByDesc('created_at')
            ->paginate(10);

        $joinedInvitations = $user->sentReferrals()
            ->with('referred')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $orgSlug = $user->organization?->slug;

        // Loaded in full rather than paginated: an Organization holds ~200 people,
        // so the list stays small and the filters below can stay client-side,
        // matching the catalog pattern and adding no new frontend dependency.
        $loopInvitations = LoopInvitation::visibleTo($user)
            ->with(['loop', 'sender'])
            ->latest()
            ->get();

        return view('invitations.index', compact(
            'referralPointsEarned',
            'sentReferralsCount',
            'activatedReferralsCount',
            'referralLink',
            'sentInvitations',
            'joinedInvitations',
            'loopInvitations',
            'orgSlug',
        ));
    }
}
