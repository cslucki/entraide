<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellUsageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1438 — SW-10 : le cockpit plateforme du Shell Welcome (Shell Welcome
 * V3 §17). Le SuperAdmin voit les totaux, la ventilation par Organization,
 * l'etat de chaque politique — jamais un contenu de conversation. Lecture
 * seule : les politiques se reglent dans /admin/ai-config.
 */
class AdminGuestShellController extends Controller
{
    public function index(Request $request, GuestShellUsageService $usage, GuestShellPolicyService $policies): View
    {
        $filters = AiConsumptionFilters::fromRequest($request);
        $month = AiConsumptionFilters::currentMonth();

        return view('admin.guest-shell.index', [
            'filters' => $filters,
            'isCurrentMonth' => $filters->from->equalTo($month->from) && $filters->to->equalTo($month->to),
        ] + $usage->platformUsage($filters->from, $filters->to, $policies));
    }
}
