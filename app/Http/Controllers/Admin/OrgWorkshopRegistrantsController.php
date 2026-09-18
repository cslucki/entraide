<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSessionInterest;
use Illuminate\View\View;

/**
 * TASK-1455 (prep) — OrgAdmin Workshops : « Inscrits & interets » d'un atelier
 * (Growth V3 §13), LECTURE SEULE V1 : par session, les inscrits (membres de
 * l'Organization : nom, email, provenance Journey/campagne/shortcut, Contact CRM
 * lie quand il existe) et les interets Guest (compteur + pseudonymes, jamais
 * de donnee personnelle — un Guest n'en a pas). Aucune mutation d'inscription
 * par l'admin. `{workshop}` est resolu DANS l'Organization (404 ailleurs).
 */
class OrgWorkshopRegistrantsController extends Controller
{
    public function show(Organization $organization, string $workshop): View
    {
        $target = Workshop::query()->forOrganization($organization)->whereKey($workshop)->firstOrFail();
        $sessions = $target->sessions()->orderBy('starts_at')->get();

        $registrations = WorkshopRegistration::query()
            ->forOrganization($organization)
            ->where('workshop_id', $target->getKey())
            ->with(['user', 'visitor', 'journey'])
            ->orderBy('registered_at')
            ->get()
            ->groupBy('workshop_session_id');

        $interests = WorkshopSessionInterest::query()
            ->forOrganization($organization)
            ->where('workshop_id', $target->getKey())
            ->selected()
            ->with('visitor')
            ->get()
            ->groupBy('workshop_session_id');

        // MASTER pulse #48 : Workshop, Registration, User ET Contact verifies dans la MEME Organization avant tout lien CRM.
        $userIds = $registrations->flatten()
            ->filter(fn (WorkshopRegistration $r) => $r->user !== null && (string) $r->user->organization_id === (string) $organization->getKey())
            ->pluck('user_id')->unique()->values();
        $contacts = $userIds->isEmpty() ? collect() : CrmContact::query()->where('organization_id', $organization->getKey())->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        return view('admin.org.workshops.registrants', [
            'organization' => $organization,
            'workshop' => $target,
            'sessions' => $sessions,
            'registrations' => $registrations,
            'interests' => $interests,
            'contacts' => $contacts,
        ]);
    }
}
