<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PointController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $entries = $user->pointLedger()
            ->with('transaction.service', 'transaction.serviceRequest')
            ->orderByDesc('created_at')
            ->paginate(20);

        $earned = $user->pointLedger()->where('delta', '>', 0)->sum('delta');
        $spent  = abs($user->pointLedger()->where('delta', '<', 0)->sum('delta'));

        // Chart data: last 60 entries to calculate cumulative balance
        $chartEntries = $user->pointLedger()
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        $history = [];
        $labels = [];
        $currentBalance = $user->points_balance;

        foreach ($chartEntries as $entry) {
            $history[] = $currentBalance;
            $labels[] = $entry->created_at->format('d/m H:i');
            $currentBalance -= $entry->delta;
        }

        // Add starting point (balance before the oldest of the 60 entries)
        if ($chartEntries->isNotEmpty()) {
            $history[] = $currentBalance;
            $labels[] = $chartEntries->last()->created_at->subMinute()->format('d/m H:i');
        } else {
            // If no history, just show current balance
            $history[] = $currentBalance;
            $labels[] = now()->format('d/m H:i');
        }

        $history = array_reverse($history);
        $labels = array_reverse($labels);

        return view('points.index', compact('entries', 'earned', 'spent', 'history', 'labels'));
    }
}
