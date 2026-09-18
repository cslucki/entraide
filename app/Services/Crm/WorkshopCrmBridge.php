<?php

namespace App\Services\Crm;

use App\Models\AcquisitionEvent;
use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\WorkshopRegistration;
use App\Services\Acquisition\AcquisitionEventRecorder;

/**
 * TASK-1457 (prep) — Workshop → CRM (Growth V3 §15, Mini-CRM V2 §10) : au
 * moment produit pertinent (participation confirmee), le membre inscrit
 * retrouve ou cree son Contact dans la MEME Organization, `source = workshop`,
 * `source_ref = workshop_session_id`, et la timeline recoit un fait REPETABLE
 * identifie par (type, source_ref) — plusieurs ateliers legitimes ne sont
 * jamais dedupliques par type seul. Idempotent ; aucun doublon Contact/User ;
 * la provenance (Journey exacte, campagne, shortcut) est conservee dans le
 * payload ; aucun transcript Guest. Non `final` : les tests substituent un
 * bridge en panne pour prouver que l'inscription tient.
 */
class WorkshopCrmBridge
{
    public function __construct(
        private readonly CrmContactService $contacts,
        private readonly CrmTimelineService $timeline,
        private readonly AcquisitionEventRecorder $events,
    ) {}

    public function onParticipationConfirmed(WorkshopRegistration $registration): ?CrmContact
    {
        $user = $registration->user;
        $organization = $registration->organization;
        if ($user === null || $organization === null || (string) $user->organization_id !== (string) $organization->getKey()) {
            return null;
        }

        $contact = $this->contacts->findOrCreate($organization, [
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->name,
            'source' => CrmContact::SOURCE_WORKSHOP,
            'source_ref' => (string) $registration->workshop_session_id,
        ]);
        // Un Contact deja lie a un AUTRE membre n'est pas le sien : rien n'y est ecrit, rien n'est vole (Mini-CRM V2 : pas de duplication, pas de reattribution).
        if ($contact->user_id !== null && (string) $contact->user_id !== (string) $user->getKey()) {
            return null;
        }
        if ($contact->user_id === null) {
            $contact = $this->contacts->linkToUser($contact, $user);
        }

        // MASTER #47 : identite = la Registration (meme registration rejouee = un fait ; atelier A puis B = deux faits).
        $this->timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED.':'.$registration->getKey(), [
            'workshop_id' => $registration->workshop_id,
            'workshop_session_id' => $registration->workshop_session_id,
            'workshop_title' => $registration->workshop?->title,
            'session_starts_at' => $registration->session?->localStartsAt()->format('d/m/Y H:i'),
            'registration_id' => $registration->getKey(),
            'acquisition_journey_id' => $registration->acquisition_journey_id,
            'utm_campaign' => $registration->visitor?->utm_campaign,
            'shortcut' => $registration->visitor?->shortcut,
        ]);

        // Growth V3 §5 : `crm_contact_linked`, une fois par (contact, inscription), avec la provenance du visiteur claime.
        $visitor = $registration->visitor;
        $dimensions = ($visitor === null ? [] : $this->events->visitorDimensions($visitor)) + ['user' => $user, 'locale' => app()->getLocale()];
        $this->events->record($organization, AcquisitionEvent::CRM_CONTACT_LINKED, $dimensions, ['crm_contact_id' => $contact->getKey(), 'registration_id' => $registration->getKey()], AcquisitionEvent::CRM_CONTACT_LINKED.':contact:'.$contact->getKey().':registration:'.$registration->getKey());

        return $contact;
    }
}
