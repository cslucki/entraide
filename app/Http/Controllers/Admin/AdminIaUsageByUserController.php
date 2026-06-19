<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminIaUsageByUserController extends Controller
{
    public function index(Request $request): View
    {
        $query = AiInteraction::query()
            ->select('user_id')
            ->selectRaw('COUNT(*) as total_interactions')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as total_output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd)::numeric, 0) as total_cost')
            ->selectRaw('MAX(created_at) as last_interaction')
            ->with('user');

        $filters = $request->only(['organization_id', 'date_from', 'date_to', 'search', 'sort', 'direction']);

        if ($orgId = $request->input('organization_id')) {
            $query->where('organization_id', $orgId);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('created_at', '>=', $dateFrom.' 00:00:00');
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        if ($search = $request->input('search')) {
            $query->whereIn('user_id', function ($sub) use ($search) {
                $sub->select('id')
                    ->from('users')
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $sort = in_array($request->input('sort'), ['user_id', 'total_interactions', 'total_input_tokens', 'total_output_tokens', 'total_cost', 'last_interaction'])
            ? $request->input('sort')
            : 'total_cost';

        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $interactions = $query->groupBy('user_id')
            ->orderBy($sort, $direction)
            ->paginate(50)
            ->withQueryString()
            ->through(function ($row) {
                $row->last_interaction = $row->last_interaction
                    ? Carbon::parse($row->last_interaction)
                    : null;

                return $row;
            });

        $organizations = Organization::orderBy('name')->get(['id', 'name']);

        return view('admin.ia-usage-by-user.index', [
            'interactions' => $interactions,
            'organizations' => $organizations,
            'filters' => $filters,
        ]);
    }
}
