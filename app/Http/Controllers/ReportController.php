<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function storeService(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        // Empêcher l'auteur de se signaler lui-même
        if (auth()->id() === $service->user_id) {
            return back()->with('error', 'Vous ne pouvez pas signaler votre propre service.');
        }

        Report::firstOrCreate(
            ['reporter_id' => auth()->id(), 'reportable_type' => Service::class, 'reportable_id' => $service->id],
            array_merge($data, ['reporter_id' => auth()->id(), 'reportable_type' => Service::class, 'reportable_id' => $service->id])
        );

        return back()->with('success', 'Signalement envoyé. Merci !');
    }

    public function storeUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        if (auth()->id() === $user->id) {
            return back()->with('error', 'Vous ne pouvez pas vous signaler vous-même.');
        }

        Report::firstOrCreate(
            ['reporter_id' => auth()->id(), 'reportable_type' => User::class, 'reportable_id' => $user->id],
            array_merge($data, ['reporter_id' => auth()->id(), 'reportable_type' => User::class, 'reportable_id' => $user->id])
        );

        return back()->with('success', 'Signalement envoyé. Merci !');
    }
}
