<?php

namespace App\Services\Crm;

use App\Models\AcquisitionEvent;
use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\GuestVisitor;
use App\Models\User;
use App\Services\Acquisition\AcquisitionEventRecorder;

/**
 * TASK-1458 (prep) — Shell → CRM (Shell Welcome V3 §23, Mini-CRM V2 §9,
 * Growth V3 §16) : au claim Guest → User (email verifie, meme Organization),
 * le Contact du membre est retrouve ou cree, `source = shell_welcome`,
 * `source_ref = guest_visitor_id`, et la timeline recoit `shell_claimed`
 * (une fois par contact + visiteur) avec la provenance (Journey exacte,
 * campagne, shortcut, conversations : compteur seulement). Le transcript
 * Guest reste dans le cockpit Guest — jamais copie dans le CRM. Idempotent ;
 * aucun doublon Contact/User ; aucun Contact a chaque message.
 *
 * MASTER pulse #49 : le claim verifie est une IDENTITE CRM (relation produit
 * identifiable), JAMAIS un consentement marketing — aucun champ de
 * contactabilite n'est touche ici, aucun outbound, aucune mention de
 * « recontact » ; la contactabilite reste l'autorite Mini-CRM existante.
 */
class ShellCrmBridge
{
    public function __construct(
        private readonly CrmContactService $contacts,
        private readonly CrmTimelineService $timeline,
        private readonly AcquisitionEventRecorder $events,
    ) {}

    public function onClaim(GuestVisitor $visitor, User $user): ?CrmContact
    {
        $organization = $visitor->organization;
        if ($organization === null || (string) $user->organization_id !== (string) $organization->getKey() || (string) $visitor->claimed_user_id !== (string) $user->getKey()) {
            return null;
        }

        $contact = $this->contacts->findOrCreate($organization, [
            'email' => $user->email,
            'first_name' => $user->first_name ?? $visitor->declared_first_name,
            'last_name' => $user->name,
            'source' => CrmContact::SOURCE_SHELL_WELCOME,
            'source_ref' => (string) $visitor->getKey(),
        ]);
        // Un Contact deja lie a un AUTRE membre n'est pas le sien : rien n'y est ecrit, rien n'est vole.
        if ($contact->user_id !== null && (string) $contact->user_id !== (string) $user->getKey()) {
            return null;
        }
        if ($contact->user_id === null) {
            $contact = $this->contacts->linkToUser($contact, $user);
        }

        $this->timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_SHELL_CLAIMED, CrmContactEvent::TYPE_SHELL_CLAIMED.':'.$visitor->getKey(), [
            'guest_visitor_id' => $visitor->getKey(),
            'acquisition_journey_id' => $visitor->acquisition_journey_id,
            'utm_campaign' => $visitor->utm_campaign,
            'shortcut' => $visitor->shortcut,
            'declared_role' => $visitor->declared_role,
            'declared_interest' => $visitor->declared_interest,
            'conversations' => $visitor->conversations()->count(),
        ]);

        // Growth V3 §5 : `crm_contact_linked`, une fois par (contact, visiteur), avec la provenance FIRST TOUCH du visiteur.
        $this->events->record($organization, AcquisitionEvent::CRM_CONTACT_LINKED, $this->events->visitorDimensions($visitor) + ['user' => $user, 'locale' => app()->getLocale()], ['crm_contact_id' => $contact->getKey(), 'guest_visitor_id' => $visitor->getKey()], AcquisitionEvent::CRM_CONTACT_LINKED.':contact:'.$contact->getKey().':visitor:'.$visitor->getKey());

        return $contact;
    }
}
