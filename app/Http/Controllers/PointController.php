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

        return view('points.index', compact('entries', 'earned', 'spent'));
    }
}
