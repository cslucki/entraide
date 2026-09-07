<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmGlobalOverviewService;
use App\Services\Crm\CrmGlobalPanelService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1425 — CRM-15 : « Relations » cote plateforme, agregats par
 * Organization seulement. Aucune ecriture, aucun contenu de tenant.
 */
class AdminCrmOverviewController extends Controller
{
    public function __construct(
        private readonly CrmGlobalOverviewService $overview,
        private readonly CrmGlobalPanelService $panel,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.crm.overview', $this->overview->overview() + $this->contactsData($request));
    }

    /** TASK-1427 — decision Cyril : le SuperAdmin voit TOUS les contacts, de toutes les Organizations (sous les agregats). */
    private function contactsData(Request $request): array
    {
        $filters = [
            'search' => (string) $request->input('search', ''),
            'organization' => (string) $request->input('organization', ''),
            'status' => (string) $request->input('status', ''),
            'due' => in_array($request->input('due'), CrmGlobalPanelService::DUE, true) ? (string) $request->input('due') : '',
            'idle' => $request->boolean('idle'),
            'contactable' => in_array($request->input('contactable'), ['yes', 'no'], true) ? (string) $request->input('contactable') : '',
        ];

        return [
            'contacts' => $this->panel->contacts($filters),
            'filters' => $filters,
            'organizations' => $this->panel->organizations(),
            'statusLabels' => $this->panel->statusLabels(),
            'idleDays' => CrmGlobalPanelService::IDLE_DAYS,
        ];
    }

    /** TASK-1427 — les echeances de toutes les Organizations, avec les noms. */
    public function today(): View
    {
        return view('admin.crm.today', ['day' => now()] + $this->panel->today());
    }

    /** TASK-1427 — les derniers faits de toutes les Organizations, AVEC leur contenu. */
    public function facts(): View
    {
        return view('admin.crm.facts', ['facts' => $this->panel->facts(), 'limit' => CrmGlobalPanelService::FACTS_LIMIT]);
    }
}
