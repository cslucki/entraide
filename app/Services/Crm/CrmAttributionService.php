<?php

namespace App\Services\Crm;

use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\GuestVisitor;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use Illuminate\Support\Carbon;

/**
 * TASK-1459 (prep) — Attribution CRM (Mini-CRM V2 §11, Growth V3 §5) : la fiche
 * Contact LIT la chaine Journey → Shortcut → Guest → User → Workshop → CRM
 * depuis les autorites existantes (AcquisitionJourney, GuestVisitor claime,
 * WorkshopRegistration) — aucune colonne divergente, aucun transcript.
 * FIRST TOUCH (audit F7, MASTER #52) : le visiteur retenu est le PREMIER acquis
 * (`first_seen_at`, puis id) parmi ceux rattaches au membre dans cette Organization
 * — jamais le dernier rattache, jamais `claimed_at` (qui date le rattachement). Tout est borne a
 * l'Organization du Contact ; un Contact sans membre n'a que sa source.
 *
 * @return array{source: string, source_ref: string|null, source_label: string|null, journey: string|null, campaign: string|null, shortcut: string|null, utm_source: string|null, referrer: string|null, first_touch_at: Carbon|null, claimed_at: Carbon|null, workshops: list<array{title: string, starts_at: string, status: string}>}
 */
class CrmAttributionService
{
    public function for(CrmContact $contact): array
    {
        $organizationId = (string) $contact->organization_id;
        $result = [
            'source' => (string) $contact->source,
            'source_ref' => $contact->source_ref,
            'source_label' => $this->sourceLabel($contact),
            'journey' => null, 'campaign' => null, 'shortcut' => null, 'utm_source' => null, 'referrer' => null,
            'first_touch_at' => null, 'claimed_at' => null, 'workshops' => [],
        ];

        $user = $contact->user;
        if ($user === null || (string) $user->organization_id !== $organizationId) {
            return $result;
        }

        // F7 : le PREMIER visiteur acquis (first touch), dans cette Organization seulement.
        $visitor = GuestVisitor::query()->where('organization_id', $organizationId)->where('claimed_user_id', $user->getKey())->orderBy('first_seen_at')->orderBy('id')->first();
        if ($visitor !== null) {
            $journey = $visitor->acquisition_journey_id ? AcquisitionJourney::query()->where('organization_id', $organizationId)->whereKey($visitor->acquisition_journey_id)->first() : null;
            $result['journey'] = $journey ? $journey->name.' v'.$journey->version : null;
            $result['campaign'] = $visitor->utm_campaign ?: $journey?->campaign;
            $result['shortcut'] = $visitor->shortcut;
            $result['utm_source'] = $visitor->utm_source;
            $result['referrer'] = $visitor->referrer;
            $result['first_touch_at'] = $visitor->first_seen_at;
            $result['claimed_at'] = $visitor->claimed_at;
        }

        $registrations = WorkshopRegistration::query()->where('organization_id', $organizationId)->where('user_id', $user->getKey())->with(['workshop', 'session', 'journey'])->orderBy('registered_at')->get();
        foreach ($registrations as $registration) {
            if ($registration->workshop === null || $registration->session === null) {
                continue;
            }
            $result['workshops'][] = [
                'title' => (string) $registration->workshop->title,
                'starts_at' => $registration->session->localStartsAt()->format('d/m/Y H:i'),
                'status' => (string) $registration->status,
            ];
            // Un inscrit sans visiteur rattache garde la Journey portee par l'inscription (provenance de T1453).
            if ($result['journey'] === null && $registration->journey !== null) {
                $result['journey'] = $registration->journey->name.' v'.$registration->journey->version;
                $result['campaign'] ??= $registration->journey->campaign;
            }
        }

        return $result;
    }

    private function sourceLabel(CrmContact $contact): ?string
    {
        if ($contact->source_ref === null || $contact->source_ref === '') {
            return null;
        }
        if ($contact->source === CrmContact::SOURCE_WORKSHOP) {
            $session = WorkshopSession::query()->where('organization_id', $contact->organization_id)->whereKey($contact->source_ref)->with('workshop')->first();

            return $session?->workshop ? $session->workshop->title.' · '.$session->localStartsAt()->format('d/m/Y H:i') : null;
        }
        if ($contact->source === CrmContact::SOURCE_SHELL_WELCOME) {
            $visitor = GuestVisitor::query()->where('organization_id', $contact->organization_id)->whereKey($contact->source_ref)->first();

            return $visitor?->pseudonym();
        }

        return null;
    }
}
