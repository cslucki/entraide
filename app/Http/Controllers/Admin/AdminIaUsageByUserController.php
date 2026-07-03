<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminIaUsageByUserController extends Controller
{
    public function index(Request $request): View
    {
        $blogSub = AiInteraction::query()
            ->select('user_id')
            ->selectRaw('COUNT(*) as total_interactions')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as total_output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd)::numeric, 0) as total_cost')
            ->selectRaw('MAX(created_at) as last_interaction')
            ->groupBy('user_id');

        $adminSub = AdminAiInteraction::query()
            ->select('user_id')
            ->selectRaw('COUNT(*) as total_interactions')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as total_output_tokens')
            ->selectRaw('COALESCE(SUM(cost_usd)::numeric, 0) as total_cost')
            ->selectRaw('MAX(created_at) as last_interaction')
            ->whereNotNull('user_id')
            ->groupBy('user_id');

        $union = $blogSub->unionAll($adminSub);

        $query = DB::table(DB::raw("({$union->toSql()}) as combined"))
            ->mergeBindings($union->getQuery())
            ->select('user_id')
            ->selectRaw('SUM(total_interactions) as total_interactions')
            ->selectRaw('SUM(total_input_tokens) as total_input_tokens')
            ->selectRaw('SUM(total_output_tokens) as total_output_tokens')
            ->selectRaw('SUM(total_cost) as total_cost')
            ->selectRaw('MAX(last_interaction) as last_interaction')
            ->groupBy('user_id');

        if ($orgId = $request->input('organization_id')) {
            $userIds = User::where('organization_id', $orgId)->pluck('id');
            $query->whereIn('combined.user_id', $userIds);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->having('last_interaction', '>=', $dateFrom.' 00:00:00');
        }

        if ($dateTo = $request->input('date_to')) {
            $query->having('last_interaction', '<=', $dateTo.' 23:59:59');
        }

        if ($search = $request->input('search')) {
            $userIds = User::where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->pluck('id');
            $query->whereIn('combined.user_id', $userIds);
        }

        $sort = in_array($request->input('sort'), ['user_id', 'total_interactions', 'total_input_tokens', 'total_output_tokens', 'total_cost', 'last_interaction'])
            ? $request->input('sort')
            : 'total_cost';

        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $perPage = 50;
        $page = $request->input('page', 1);
        $total = $query->count();

        $rawResults = $query
            ->orderBy($sort, $direction)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $userIds = $rawResults->pluck('user_id')->filter()->unique();
        $users = User::whereIn('id', $userIds)->with('organization')->get()->keyBy('id');

        $interactions = $rawResults->map(function ($row) use ($users) {
            $row->user = $users->get($row->user_id);
            $row->last_interaction = $row->last_interaction
                ? Carbon::parse($row->last_interaction)
                : null;

            return $row;
        });

        $paginator = new LengthAwarePaginator(
            $interactions,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $organizations = Organization::orderBy('name')->get(['id', 'name']);

        return view('admin.ia-usage-by-user.index', [
            'interactions' => $paginator,
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id', 'date_from', 'date_to', 'search', 'sort', 'direction']),
        ]);
    }
}
