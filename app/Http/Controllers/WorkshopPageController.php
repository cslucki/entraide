<?php

namespace App\Http\Controllers;

use App\Models\AcquisitionEvent;
use App\Models\Organization;
use App\Models\Workshop;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopInterestService;
use App\Services\Workshops\WorkshopRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private readonly WorkshopRegistrationService $registrations,
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
            // TASK-1453 : l'etat MEMBRE — inscriptions, droit de confirmer (verifie + meme Organization), sessions choisies en Guest (visiteurs claimes).
            'member' => $this->memberState($request, $organization, $workshop),
            // TASK-1611 : l'apercu social de CETTE page, construit ici.
            'social' => $this->socialMeta($organization, $workshop),
        ]);
    }

    /**
     * TASK-1611 — l'apercu social d'un atelier, DECLARE par la page.
     *
     * TASK-1610 a interdit au layout partage de deduire `og:url` de la
     * requete, et cette interdiction tient : l'URL courante d'une page de
     * refus porte l'identifiant refuse. Ici c'est l'inverse — la page EST
     * publique, elle connait son atelier, elle peut donc nommer son URL
     * canonique a partir de l'identite metier (slug d'Organization + slug
     * d'atelier), jamais a partir de l'URL parcourue. Les parametres
     * d'attribution presents dans l'URL visitee n'entrent pas dans la
     * canonique : partager la page ne doit pas partager le canal d'arrivee.
     *
     * L'image : le flyer s'il existe — il devient la vignette et fait passer
     * la carte Twitter en grand format. Sinon l'icone de marque 512 px, qui
     * reste sous le seuil des grandes cartes mais est lisible la ou le
     * symbole 64 px du repli global ne l'est pas.
     *
     * HOTE : tout est bati sur `config('app.url')`, jamais sur l'hote de la
     * requete. `route()` absolu et `asset()` reprennent le domaine par lequel
     * on est arrive — donc, sur un domaine de courtoisie, une canonique qui
     * DESIGNE ce domaine de courtoisie, c'est-a-dire l'inverse de ce qu'une
     * canonique promet ; et un `og:image` qui pouvait deja diverger de
     * `og:url`, `Workshop::flyerUrl()` etant, lui, deja ancre sur `APP_URL`.
     *
     * @return array<string, string|int|null>
     */
    private function socialMeta(Organization $organization, Workshop $workshop): array
    {
        $url = $this->canonicalUrl(
            route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $workshop->slug], absolute: false)
        );

        // Texte BRUT et court : la promesse est deja ecrite pour etre lue seule ;
        // a defaut, le debut de la description, sans retours ni espaces doubles.
        $source = filled($workshop->promise) ? $workshop->promise : (string) $workshop->description;
        $description = Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($source)) ?? ''), 200);

        if ($workshop->hasFlyer()) {
            return [
                'url' => $url,
                'description' => $description,
                'image' => $workshop->flyerUrl(),
                'imageAlt' => __('workshops.flyer_alt', ['title' => $workshop->title]),
                'imageType' => $workshop->flyerMimeType(),
                'imageWidth' => $workshop->flyer_width,
                'imageHeight' => $workshop->flyer_height,
                'twitterCard' => 'summary_large_image',
            ];
        }

        return [
            'url' => $url,
            'description' => $description,
            'image' => $this->canonicalUrl('/brand/icon-512.png'),
            'imageAlt' => null,
            'imageType' => 'image/png',
            'imageWidth' => 512,
            'imageHeight' => 512,
            'twitterCard' => 'summary',
        ];
    }

    /**
     * Un chemin de l'application, ancre sur le domaine CANONIQUE.
     *
     * `config('app.url')` est la seule source : aucun domaine ecrit en dur,
     * aucune lecture de la requete. Meme regle que `Workshop::flyerUrl()`,
     * pour que `og:url`, `canonical` et `og:image` d'une meme page ne
     * puissent pas designer deux hotes differents.
     */
    private function canonicalUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array{present: bool, sameOrganization: bool, verified: bool, registeredSessionIds: list<string>, guestSelectedSessionIds: list<string>}
     */
    private function memberState(Request $request, Organization $organization, Workshop $workshop): array
    {
        $user = $request->user();
        if ($user === null) {
            return ['present' => false, 'sameOrganization' => false, 'verified' => false, 'registeredSessionIds' => [], 'guestSelectedSessionIds' => []];
        }
        $same = (string) $user->organization_id === (string) $organization->getKey();

        return [
            'present' => true,
            'sameOrganization' => $same,
            'verified' => $user->email_verified_at !== null,
            'registeredSessionIds' => $same ? $this->registrations->registeredSessionIds($user, (string) $workshop->getKey()) : [],
            // Les sessions choisies en Guest par les visiteurs rattaches a ce compte (claim SW-11) : mises en evidence, jamais converties sans geste.
            'guestSelectedSessionIds' => $same ? $this->registrations->guestSelectedSessionIds($user, (string) $workshop->getKey()) : [],
        ];
    }
}
