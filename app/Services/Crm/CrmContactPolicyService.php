<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TASK-1422 — CRM-13 : la contactabilite d'un Contact, decidee explicitement.
 *
 * « Ne pas contacter » est une doctrine de consentement, pas une case a
 * cocher : chaque changement porte une RAISON bornee, une note optionnelle,
 * un auteur, un horodatage — et laisse un fait dans la timeline. Le meme
 * etat redemande n'ecrit rien. Aucun effet retroactif sur l'historique.
 *
 * L'effet, lui, est deja fail-closed en aval (T1421 : aucun email ne part
 * vers un Contact non contactable ; les canaux WhatsApp/SMS heriteront de la
 * meme barriere).
 */
class CrmContactPolicyService
{
    public const REASONS = ['contact_request', 'data_error', 'other'];

    public const MAX_NOTE_LENGTH = 500;

    /** Passe le Contact en « ne pas contacter ». Retourne null si c'etait deja le cas. */
    public function block(CrmContact $contact, string $reason, ?string $note, User $actor): ?CrmContactEvent
    {
        $this->guard($contact, $reason, $actor);

        if (! $contact->isContactable()) {
            return null;
        }

        return DB::transaction(function () use ($contact, $reason, $note, $actor) {
            $now = now();
            $contact->do_not_contact_at = $now;
            $contact->save();

            return $this->record($contact, true, false, $reason, $note, $actor);
        });
    }

    /** Rend le Contact contactable a nouveau. Retourne null s'il l'etait deja. */
    public function allow(CrmContact $contact, string $reason, ?string $note, User $actor): ?CrmContactEvent
    {
        $this->guard($contact, $reason, $actor);

        if ($contact->isContactable()) {
            return null;
        }

        return DB::transaction(function () use ($contact, $reason, $note, $actor) {
            $contact->do_not_contact_at = null;
            $contact->save();

            return $this->record($contact, false, true, $reason, $note, $actor);
        });
    }

    /** Payload minimal (MASTER Q31) : from_contactable → to_contactable, raison, note ; auteur et date par la timeline. */
    private function record(CrmContact $contact, bool $fromContactable, bool $toContactable, string $reason, ?string $note, User $actor): CrmContactEvent
    {
        $note = trim((string) $note);

        return CrmContactEvent::create([
            'organization_id' => $contact->organization_id,
            'crm_contact_id' => $contact->id,
            'type' => CrmContactEvent::TYPE_CONTACT_POLICY_CHANGED,
            'author_user_id' => $actor->id,
            'occurred_at' => now(),
            'payload' => [
                'from_contactable' => $fromContactable,
                'to_contactable' => $toContactable,
                'reason' => $reason,
                'note' => $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE_LENGTH),
            ],
        ]);
    }

    private function guard(CrmContact $contact, string $reason, User $actor): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new LogicException("Unknown CRM contact policy reason [{$reason}].");
        }

        if (! $actor->is_admin && $actor->organization_id !== $contact->organization_id) {
            throw new LogicException('A CRM contact policy can only be changed by a member of the contact Organization.');
        }
    }
}
