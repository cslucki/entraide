<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReferralController extends Controller
{
    public function index()
    {
        $stats = [
            'total_referrals' => Referral::count(),
            'paid_registrations' => Referral::where('registration_reward_paid', true)->count(),
            'paid_first_tx' => Referral::where('first_transaction_reward_paid', true)->count(),
            'top_referrers' => User::withCount('referrals')
                ->orderBy('referrals_count', 'desc')
                ->take(5)
                ->get(),
        ];

        $referrals = Referral::with(['referrer', 'referee'])
            ->latest()
            ->paginate(20);

        return view('admin.referrals.index', compact('stats', 'referrals'));
    }
}
