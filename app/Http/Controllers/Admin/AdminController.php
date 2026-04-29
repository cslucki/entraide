<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $stats = [
            'users'        => User::count(),
            'services'     => Service::where('status', 'active')->count(),
            'transactions' => Transaction::count(),
            'completed'    => Transaction::where('status', 'completed')->count(),
            'points'       => User::sum('points_balance'),
            'reports'      => Report::where('status', 'pending')->count(),
        ];

        $recentUsers = User::latest()->limit(5)->get();
        $pendingReports = Report::with('reporter')->where('status', 'pending')->latest('created_at')->limit(10)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers', 'pendingReports'));
    }

    public function users(Request $request): View
    {
        $query = User::withCount(['services', 'buyerTransactions', 'sellerTransactions', 'reviewsReceived']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('email', 'like', '%'.$request->search.'%');
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        return view('admin.users', compact('users'));
    }

    public function reports(): View
    {
        $reports = Report::with('reporter')->latest('created_at')->paginate(20);
        return view('admin.reports', compact('reports'));
    }

    public function dismissReport(Report $report): RedirectResponse
    {
        $report->update(['status' => 'dismissed']);
        return back()->with('success', 'Signalement classé.');
    }

    public function reviewReport(Report $report): RedirectResponse
    {
        $report->update(['status' => 'reviewed']);
        return back()->with('success', 'Signalement marqué comme traité.');
    }

    public function toggleUserAvailability(User $user): RedirectResponse
    {
        $user->update(['is_available' => !$user->is_available]);
        return back()->with('success', 'Disponibilité modifiée.');
    }

    public function toggleUserAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas modifier vos propres droits admin.');
        }
        $user->update(['is_admin' => !$user->is_admin]);
        return back()->with('success', 'Droits admin modifiés.');
    }
}
