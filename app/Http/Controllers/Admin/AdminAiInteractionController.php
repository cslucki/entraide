<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiInteraction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiInteractionController extends Controller
{
    public function index(Request $request): View
    {
        $query = AdminAiInteraction::query()
            ->orderBy('created_at', 'desc');

        // Filters
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        if ($request->filled('scenario_id')) {
            $query->where('scenario_id', $request->input('scenario_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from').' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to').' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('input_excerpt', 'ilike', "%{$search}%")
                    ->orWhere('result_summary', 'ilike', "%{$search}%");
            });
        }

        $interactions = $query->paginate(25)->withQueryString();

        // Filter options for dropdowns
        $providers = AdminAiInteraction::query()
            ->distinct()
            ->whereNotNull('provider')
            ->pluck('provider');

        $scenarios = AdminAiInteraction::query()
            ->distinct()
            ->pluck('scenario_id');

        return view('admin.ai-interactions.index', [
            'interactions' => $interactions,
            'providers' => $providers,
            'scenarios' => $scenarios,
            'filters' => $request->only(['provider', 'scenario_id', 'status', 'date_from', 'date_to', 'search']),
        ]);
    }

    public function show(AdminAiInteraction $interaction): View
    {
        return view('admin.ai-interactions.show', [
            'interaction' => $interaction,
        ]);
    }
}
