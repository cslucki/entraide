<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAiUsageController extends Controller
{
    public function index(Request $request): View
    {
        $feature = $request->input('feature');
        $search = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $source = $request->input('source');

        $blogQuery = AiInteraction::query()->with(['user', 'organization']);
        $adminQuery = AdminAiInteraction::query()->with(['user']);

        if ($feature) {
            $blogQuery->where('feature', $feature);
        }

        if ($source === 'admin' || ! $source) {
            if ($dateFrom) {
                $adminQuery->where('created_at', '>=', $dateFrom.' 00:00:00');
            }
            if ($dateTo) {
                $adminQuery->where('created_at', '<=', $dateTo.' 23:59:59');
            }
            if ($search) {
                $adminQuery->where(function ($q) use ($search) {
                    $q->where('input_excerpt', 'ilike', "%{$search}%")
                        ->orWhere('result_summary', 'ilike', "%{$search}%");
                });
            }
        }

        if ($source === 'blog' || ! $source) {
            if ($dateFrom) {
                $blogQuery->where('created_at', '>=', $dateFrom.' 00:00:00');
            }
            if ($dateTo) {
                $blogQuery->where('created_at', '<=', $dateTo.' 23:59:59');
            }
            if ($search) {
                $blogQuery->where(function ($q) use ($search) {
                    $q->where('prompt', 'ilike', "%{$search}%")
                        ->orWhere('response', 'ilike', "%{$search}%");
                });
            }
        }

        $blogInteractions = $blogQuery->latest('created_at')->paginate(25, ['*'], 'blog_page');
        $adminInteractions = $adminQuery->latest('created_at')->paginate(25, ['*'], 'admin_page');

        $features = AiInteraction::distinct()->whereNotNull('feature')->pluck('feature');

        return view('admin.ia-usage.index', [
            'blogInteractions' => $blogInteractions,
            'adminInteractions' => $adminInteractions,
            'features' => $features,
            'filters' => $request->only(['feature', 'source', 'date_from', 'date_to', 'search']),
        ]);
    }

    public function show(AiInteraction $interaction): View
    {
        $interaction->load(['user', 'organization']);

        return view('admin.ia-usage.show', [
            'interaction' => $interaction,
            'source' => 'blog',
        ]);
    }

    public function showAdmin(AdminAiInteraction $interaction): View
    {
        $interaction->load(['user']);

        return view('admin.ia-usage.show', [
            'interaction' => $interaction,
            'source' => 'admin',
        ]);
    }
}
