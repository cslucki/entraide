<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\View\View;

class InvitationController extends Controller
{
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

        return view('invitations.index', compact(
            'referralPointsEarned',
            'sentReferralsCount',
            'activatedReferralsCount',
            'referralLink',
            'sentInvitations',
            'joinedInvitations',
            'orgSlug',
        ));
    }
}
