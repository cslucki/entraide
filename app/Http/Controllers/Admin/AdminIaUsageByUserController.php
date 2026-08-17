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
        // TASK-1223 : correction LOCALE des trois defauts economiques signales
        // (TASK-306). (1) La periode filtre desormais les LIGNES sommees
        // (WHERE created_at), plus seulement la date du dernier appel. (2) Le
        // cout inconnu n'est plus COALESCE en 0 : cout CONNU somme d'un cote,
        // appels non mesurables COMPTES de l'autre — et plus de `::numeric`
        // non portable. (3) Le cumul reste une addition BRUTE de deux
        // registres de traces (un meme appel historique peut y figurer deux
        // fois) : la vue l'annonce ; le decompte canonique par invocation vit
        // dans le cockpit IA/RAG.
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $windowed = static function ($query) use ($dateFrom, $dateTo) {
            if ($dateFrom) {
                $query->where('created_at', '>=', $dateFrom.' 00:00:00');
            }
            if ($dateTo) {
                $query->where('created_at', '<=', $dateTo.' 23:59:59');
            }

            return $query;
        };

        $blogSub = $windowed(AiInteraction::query())
            ->select('user_id')
            ->selectRaw('COUNT(*) as total_interactions')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as total_output_tokens')
            ->selectRaw('SUM(CASE WHEN cost_unknown = false THEN cost_usd END) as known_cost')
            ->selectRaw('COUNT(CASE WHEN cost_unknown = true THEN 1 END) as unknown_count')
            ->selectRaw('MAX(created_at) as last_interaction')
            ->groupBy('user_id');

        $adminSub = $windowed(AdminAiInteraction::query())
            ->select('user_id')
            ->selectRaw('COUNT(*) as total_interactions')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as total_output_tokens')
            ->selectRaw('SUM(CASE WHEN cost_unknown = false THEN cost_usd END) as known_cost')
            ->selectRaw('COUNT(CASE WHEN cost_unknown = true THEN 1 END) as unknown_count')
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
            ->selectRaw('SUM(known_cost) as known_cost')
            ->selectRaw('SUM(unknown_count) as unknown_count')
            ->selectRaw('MAX(last_interaction) as last_interaction')
            ->groupBy('user_id');

        if ($orgId = $request->input('organization_id')) {
            $userIds = User::where('organization_id', $orgId)->pluck('id');
            $query->whereIn('combined.user_id', $userIds);
        }

        if ($search = $request->input('search')) {
            $userIds = User::where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->pluck('id');
            $query->whereIn('combined.user_id', $userIds);
        }

        $sort = in_array($request->input('sort'), ['user_id', 'total_interactions', 'total_input_tokens', 'total_output_tokens', 'known_cost', 'last_interaction'])
            ? $request->input('sort')
            : 'known_cost';

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
