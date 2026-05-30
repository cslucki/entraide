<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use Illuminate\View\View;

class AdminReferralController extends Controller
{
    public function index(): View
    {
        $totalReferrals = Referral::withoutGlobalScope(BelongsToOrganizationScope::class)->count();
        $pendingReferrals = Referral::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('status', 'pending')->count();
        $activatedReferrals = Referral::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('status', 'activated')->count();
        $distributedReferralPoints = ReferralReward::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->sum('points');

        $recentInvitations = Referral::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->with(['referrer', 'referred'])
            ->latest()
            ->limit(20)
            ->get();

        $recentActivations = Referral::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->with(['referrer', 'referred'])
            ->whereNotNull('activated_at')
            ->latest('activated_at')
            ->limit(20)
            ->get();

        $contributors = User::whereHas('sentReferrals', function ($q) {
                $q->withoutGlobalScope(BelongsToOrganizationScope::class);
            })
            ->withCount(['sentReferrals as invitations_count' => function ($q) {
                $q->withoutGlobalScope(BelongsToOrganizationScope::class);
            }])
            ->withCount(['sentReferrals as activations_count' => function ($q) {
                $q->withoutGlobalScope(BelongsToOrganizationScope::class)
                    ->where('status', 'activated');
            }])
            ->orderByDesc(
                Referral::withoutGlobalScope(BelongsToOrganizationScope::class)
                    ->select('created_at')
                    ->whereColumn('referrer_user_id', 'users.id')
                    ->latest()
                    ->limit(1)
            )
            ->limit(20)
            ->get();

        return view('admin.referrals', compact(
            'totalReferrals', 'pendingReferrals', 'activatedReferrals',
            'distributedReferralPoints', 'recentInvitations', 'recentActivations',
            'contributors',
        ));
    }
}
