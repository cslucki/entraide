<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Workshop;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1450 — La page PUBLIQUE d'un atelier (Growth V3 §8) :
 * `/org/{organization}/ateliers/{workshop}`.
 *
 * Fail-closed : Organization active ET publique, atelier PUBLIE de CETTE
 * Organization par son slug — sinon 404 (publiquement, la ressource n'existe
 * pas). Expose : titre, promesse, description, format, duree. Jamais :
 * meeting_url (n'existe pas ici), participants, notes internes, CRM,
 * credentials. Aucune session ni CTA d'inscription inventee avant B4.
 *
 * Le Shell Welcome n'est PAS affiche sur cette page (MASTER Q76 : PageContext
 * `workshop_page` resolvable, Shell non eligible).
 */
class WorkshopPageController extends Controller
{
    public function show(Request $request, string $organization, string $workshop): View
    {
        $organization = Organization::findBySlug($organization);
        abort_if($organization === null || ! $organization->is_active || ! $organization->is_public, 404);

        $workshop = Workshop::query()->forOrganization($organization)->published()->where('slug', $workshop)->first();
        abort_if($workshop === null, 404);

        return view('organization.workshop', ['organization' => $organization, 'workshop' => $workshop]);
    }
}
