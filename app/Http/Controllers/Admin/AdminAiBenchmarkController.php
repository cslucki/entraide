<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiInteraction;
use Illuminate\View\View;

class AdminAiBenchmarkController extends Controller
{
    public function index(): View
    {
        $totalCost = AdminAiInteraction::sum('cost_usd');
        $totalInteractions = AdminAiInteraction::count();
        $avgLatency = AdminAiInteraction::avg('latency_ms');
        $avgTokens = AdminAiInteraction::selectRaw('COALESCE(AVG(input_tokens + output_tokens), 0) as avg_tokens')->value('avg_tokens');

        $byProvider = AdminAiInteraction::query()
            ->selectRaw("provider, COUNT(*) as calls, SUM(cost_usd) as total_cost, SUM(input_tokens + output_tokens) as total_tokens, ROUND(AVG(latency_ms)) as avg_latency")
            ->whereNotNull('provider')
            ->groupBy('provider')
            ->orderByDesc('total_cost')
            ->get();

        $byScenario = AdminAiInteraction::query()
            ->selectRaw("scenario_id, COUNT(*) as calls, SUM(cost_usd) as total_cost")
            ->groupBy('scenario_id')
            ->orderByDesc('total_cost')
            ->get();

        $lastInteractions = AdminAiInteraction::query()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'scenario_id', 'provider', 'model', 'status', 'cost_usd', 'created_at']);

        return view('admin.ai-benchmark.index', [
            'totalCost' => $totalCost,
            'totalInteractions' => $totalInteractions,
            'avgLatency' => $avgLatency,
            'avgTokens' => $avgTokens,
            'byProvider' => $byProvider,
            'byScenario' => $byScenario,
            'lastInteractions' => $lastInteractions,
        ]);
    }
}
