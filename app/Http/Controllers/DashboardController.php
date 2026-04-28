<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $earned = $user->pointLedger()->where('delta', '>', 0)->sum('delta');
        $spent = abs($user->pointLedger()->where('delta', '<', 0)->sum('delta'));
        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();

        $myServices = $user->services()->where('status', 'active')->with('category')->latest()->get();
        $myRequests = $user->serviceRequests()->where('status', 'open')->with('category')->latest()->get();

        $myProposals = Transaction::where('buyer_id', $user->id)
            ->whereIn('status', ['pending', 'accepted', 'buyer_done'])
            ->with(['service', 'serviceRequest', 'seller'])
            ->latest()
            ->get();

        $activeExchanges = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->whereIn('status', ['accepted', 'buyer_done'])
            ->with(['buyer', 'seller', 'service', 'serviceRequest'])
            ->latest()
            ->get();

        $recentMessages = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->whereIn('status', ['pending', 'accepted', 'buyer_done'])
            ->with(['buyer', 'seller', 'service', 'serviceRequest', 'messages' => fn($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        return view('dashboard', compact(
            'user', 'earned', 'spent', 'completedCount',
            'myServices', 'myRequests', 'myProposals', 'activeExchanges', 'recentMessages'
        ));
    }
}
