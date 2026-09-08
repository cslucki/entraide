<?php

namespace App\Http\Controllers;

use App\Models\AcquisitionEvent;
use App\Models\OrganizationShortcut;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Acquisition\AcquisitionJourneyResolver;
use App\Services\Acquisition\GuestAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * TASK-1447 — `/s/{code}` (Growth V2 §5, MASTER Q75) : resolution SERVEUR,
 * Organization active et publique, Shortcut actif, 302 vers la destination
 * canonique avec une query string TRANSPARENTE (shortcut, journey, campaign,
 * UTM autorises et bornes). Aucun cookie, aucun Guest : l'attribution du
 * visiteur n'est posee qu'au premier geste Guest, depuis le Shortcut relu en base.
 *
 * TASK-1449 (Growth V3 §5/§6, MASTER Q77) : le fait `shortcut_opened` est
 * journalise AVANT la redirection — une ligne par ouverture (jamais
 * dedupliquee : on mesure les ouvertures), avec la Journey en version EXACTE
 * publiee a cet instant, le shortcut, les UTM bornes et le referrer borne ;
 * ni IP ni User-Agent. La telemetrie ne casse jamais l'acquisition : sous
 * `rescue()`, un journal en panne laisse partir le 302.
 */
class ShortcutController extends Controller
{
    public function __construct(
        private readonly AcquisitionEventRecorder $events,
        private readonly AcquisitionJourneyResolver $journeys,
    ) {}

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $shortcut = OrganizationShortcut::query()->active()->where('code', $code)->with('organization')->first();
        abort_if($shortcut === null || $shortcut->organization === null || ! $shortcut->organization->is_active || ! $shortcut->organization->is_public, 404);

        $utm = GuestAttribution::allowedUtm($request->query());
        $organization = $shortcut->organization;

        rescue(fn () => $this->events->record($organization, AcquisitionEvent::SHORTCUT_OPENED, [
            'journey' => $shortcut->acquisition_journey_key === null ? null : $this->journeys->published($organization, $shortcut->acquisition_journey_key),
            'shortcut' => $shortcut->code,
            'referrer' => $request->headers->get('referer'),
            // Hors groupe Organization, la locale de la requete est celle de la plateforme : le fait porte celle de l'Organization ciblee.
            'locale' => $organization->locale ?: app()->getLocale(),
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            // La campagne canonique du Shortcut prime sur l'UTM externe — la meme regle que GuestAttribution.
            'utm_campaign' => $shortcut->campaign ?? ($utm['utm_campaign'] ?? null),
        ]));

        return redirect()->to($shortcut->destinationUrl($utm), 302);
    }
}
