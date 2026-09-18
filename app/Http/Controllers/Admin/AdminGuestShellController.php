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
 * l'etat de chaque politique — jamais un contenu de conversation.
 *
 * TASK-1500 — il REGLE aussi depuis ici (decision Cyril, 10/09/2026). Le
 * contrat « lecture seule : les politiques se reglent dans /admin/ai-config »
 * est retire : mesurer puis devoir changer de page pour agir etait le
 * detour que le cockpit devait epargner. Le formulaire est le MEME partial
 * que /admin/ai-config et poste au MEME endpoint — aucune seconde ecriture
 * de politique n'apparait ; seule la page de retour change (`redirect_to`).
 */
class AdminGuestShellController extends Controller
{
    public function index(Request $request, GuestShellUsageService $usage, GuestShellPolicyService $policies): View
    {
        $filters = AiConsumptionFilters::fromRequest($request);
        $month = AiConsumptionFilters::currentMonth();

        $platform = $usage->platformUsage($filters->from, $filters->to, $policies);

        // TASK-1500 : l'etat complet de chaque politique, pour le formulaire
        // partage. `state()` est l'autorite que /admin/ai-config lit deja.
        $guestShellStates = [];
        foreach ($platform['organizations'] as $row) {
            $guestShellStates[$row['organization']->id] = $policies->state($row['organization']);
        }

        return view('admin.guest-shell.index', [
            'filters' => $filters,
            'isCurrentMonth' => $filters->from->equalTo($month->from) && $filters->to->equalTo($month->to),
            'guestShellStates' => $guestShellStates,
        ] + $platform);
    }
}
