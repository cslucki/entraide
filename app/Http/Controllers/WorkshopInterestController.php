<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Services\Acquisition\GuestAttribution;
use App\Services\GuestShell\GuestIdentityThrottle;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopInterestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * TASK-1452 — B4-B : « Je choisis cette session » depuis la page publique
 * d'un atelier (Growth V3 §10). Premier geste Guest possible : l'identite
 * pseudonyme est creee ICI si elle n'existe pas (cookie first-party, comme le
 * Shell), avec l'attribution de la page d'atterrissage relue en base
 * (TASK-1447). Un interet n'est PAS une inscription.
 *
 * Fail-closed : Organization active + publique, atelier publie, session
 * publiee a venir de CET atelier — sinon 404. Un User connecte n'exprime pas
 * d'interet Guest : il sera invite a s'inscrire par le flux canonique.
 */
class WorkshopInterestController extends Controller
{
    public function __construct(
        private readonly GuestVisitorResolver $visitors,
        private readonly WorkshopInterestService $interests,
        private readonly GuestAttribution $attribution,
        private readonly GuestIdentityThrottle $identities,
    ) {}

    public function select(Request $request, string $organization, string $workshop, string $session): RedirectResponse
    {
        [$target, $page, $row] = $this->resolve($organization, $workshop, $session);
        abort_if($request->user() !== null, 404);

        $data = $request->validate([
            'attribution' => ['sometimes', 'array:shortcut,utm_source,utm_medium,utm_campaign'],
            'attribution.shortcut' => ['nullable', 'string', 'max:32'],
            'attribution.utm_source' => ['nullable', 'string', 'max:200'],
            'attribution.utm_medium' => ['nullable', 'string', 'max:200'],
            'attribution.utm_campaign' => ['nullable', 'string', 'max:200'],
        ]);

        // TASK-1460 (V3 §3, audit F1) : anti-rafale PRE-IDENTITE par Organization — avant toute creation de visiteur.
        abort_if($this->visitors->find($request, $target) === null && ! $this->identities->allowNewIdentity($target), 429);

        $visitor = $this->visitors->ensure($request, $target, [
            'locale' => app()->getLocale(),
            'referrer' => $request->headers->get('referer'),
        ] + $this->attribution->resolve($target, $data['attribution'] ?? []));

        $this->interests->select($row, $visitor);

        return redirect()->route('organization.workshop.show', ['organization' => $target->slug, 'workshop' => $page->slug])->with('workshop_interest', $row->getKey());
    }

    public function withdraw(Request $request, string $organization, string $workshop, string $session): RedirectResponse
    {
        [$target, $page, $row] = $this->resolve($organization, $workshop, $session);
        abort_if($request->user() !== null, 404);

        $visitor = $this->visitors->find($request, $target);
        if ($visitor !== null) {
            $this->interests->withdraw($row, $visitor);
        }

        return redirect()->route('organization.workshop.show', ['organization' => $target->slug, 'workshop' => $page->slug]);
    }

    /** @return array{0: Organization, 1: Workshop, 2: WorkshopSession} */
    private function resolve(string $organization, string $workshop, string $session): array
    {
        $target = Organization::findBySlug($organization);
        abort_if($target === null || ! $target->is_active || ! $target->is_public, 404);

        $page = Workshop::query()->forOrganization($target)->published()->where('slug', $workshop)->first();
        abort_if($page === null, 404);

        $row = $page->publicUpcomingSessions()->whereKey($session)->first();
        abort_if($row === null, 404);

        return [$target, $page, $row];
    }
}
