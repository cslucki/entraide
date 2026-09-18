<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcquisitionEvent;
use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1456 (prep) — SuperAdmin Workshops (Growth V3 §14) : vue transversale
 * LECTURE SEULE — ateliers, sessions, inscriptions, interets, conversions et
 * provenance, toutes Organizations, filtre par Organization. Aucune mutation :
 * toute ecriture reste dans l'Organization explicite via ses routes OrgAdmin.
 */
class AdminWorkshopController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = (string) $request->query('organization', '');
        $organization = $organizationId === '' ? null : Organization::query()->find($organizationId);

        $workshops = Workshop::query()
            ->when($organization !== null, fn ($q) => $q->forOrganization($organization))
            ->with(['organization', 'journey'])
            ->withCount([
                'sessions',
                'registrations' => fn ($q) => $q->where('status', WorkshopRegistration::STATUS_REGISTERED),
            ])
            ->orderBy('organization_id')->orderBy('title')
            ->get();

        $workshopIds = $workshops->pluck('id');
        $sessions = WorkshopSession::query()->whereIn('workshop_id', $workshopIds)->orderBy('starts_at')->get()->groupBy('workshop_id');
        $interests = WorkshopSessionInterest::query()->whereIn('workshop_id', $workshopIds)->selected()->selectRaw('workshop_id, count(*) as total')->groupBy('workshop_id')->pluck('total', 'workshop_id');
        $conversions = AcquisitionEvent::query()
            ->where('event', AcquisitionEvent::CONVERTED)
            ->when($organization !== null, fn ($q) => $q->where('organization_id', $organization->getKey()))
            ->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');

        return view('admin.workshops.index', [
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'selected' => $organization,
            'workshops' => $workshops,
            'sessions' => $sessions,
            'interests' => $interests,
            'conversions' => $conversions,
        ]);
    }
}
