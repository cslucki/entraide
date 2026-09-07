<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmGlobalOverviewService;
use Illuminate\View\View;

/**
 * TASK-1425 — CRM-15 : « Relations » cote plateforme, agregats par
 * Organization seulement. Aucune ecriture, aucun contenu de tenant.
 */
class AdminCrmOverviewController extends Controller
{
    public function __construct(private readonly CrmGlobalOverviewService $overview) {}

    public function index(): View
    {
        return view('admin.crm.overview', $this->overview->overview());
    }
}
