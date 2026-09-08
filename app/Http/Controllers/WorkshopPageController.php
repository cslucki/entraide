<?php

namespace App\Http\Controllers;

use App\Models\AcquisitionEvent;
use App\Models\Organization;
use App\Models\Workshop;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopInterestService;
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
 * credentials. TASK-1451 (B4-A) : les sessions PUBLIEES a venir (date locale,
 * fuseau, lieu, capacite informative) — aucune meeting_url, aucune inscription.
 *
 * Le Shell Welcome n'est PAS affiche sur cette page (MASTER Q76 : PageContext
 * `workshop_page` resolvable, Shell non eligible).
 */
class WorkshopPageController extends Controller
{
    public function __construct(
        private readonly GuestVisitorResolver $visitors,
        private readonly WorkshopInterestService $interests,
        private readonly AcquisitionEventRecorder $events,
    ) {}

    public function show(Request $request, string $organization, string $workshop): View
    {
        $organization = Organization::findBySlug($organization);
        abort_if($organization === null || ! $organization->is_active || ! $organization->is_public, 404);

        $workshop = Workshop::query()->forOrganization($organization)->published()->where('slug', $workshop)->first();
        abort_if($workshop === null, 404);

        // TASK-1452 (B4-B) : lecture PURE du cookie Guest (jamais ensure() : afficher ne cree aucune identite) ;
        // un visiteur connu voit ses sessions choisies et le fait `workshop_viewed` est journalise une fois par atelier.
        $visitor = $request->user() === null ? $this->visitors->find($request, $organization) : null;
        $selected = $visitor === null ? [] : $this->interests->selectedSessionIds($visitor, (string) $workshop->getKey());
        if ($visitor !== null) {
            rescue(fn () => $this->events->record($organization, AcquisitionEvent::WORKSHOP_VIEWED, $this->events->visitorDimensions($visitor), ['workshop' => $workshop->slug], AcquisitionEvent::WORKSHOP_VIEWED.':visitor:'.$visitor->getKey().':workshop:'.$workshop->getKey()));
        }

        return view('organization.workshop', [
            'organization' => $organization,
            'workshop' => $workshop,
            'sessions' => $workshop->publicUpcomingSessions()->get(),
            'selectedSessionIds' => $selected,
            'canSelect' => $request->user() === null,
        ]);
    }
}
