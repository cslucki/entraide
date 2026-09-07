<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TASK-1418 — CRM-6 : la prochaine action d'un Contact.
 *
 * Pas de moteur Task : une seule action a la fois, portee par le Contact
 * (type, JOUR, heure optionnelle, libelle court). Planifier remplace l'etat
 * courant ; marquer faite l'efface. Chaque geste laisse un fait dans la
 * timeline — la memoire de ce qui etait prevu — et aucun ne touche
 * `last_interaction_at` : planifier ou terminer une tache interne n'est pas
 * un contact avec la personne.
 */
class CrmNextActionService
{
    public const TYPES = ['call', 'meeting', 'email', 'whatsapp', 'sms', 'quote', 'other'];

    public const MAX_LABEL_LENGTH = 120;

    /**
     * @param  string|null  $time  « HH:MM » ou null (sans heure)
     */
    public function plan(CrmContact $contact, string $type, CarbonInterface $date, ?string $time, ?string $label, User $actor): CrmContactEvent
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new LogicException("Unknown CRM next action type [{$type}].");
        }

        $time = $this->cleanTime($time);
        $label = trim((string) $label);
        $label = $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);

        $this->guardActor($contact, $actor);

        return DB::transaction(function () use ($contact, $type, $date, $time, $label, $actor) {
            $contact->next_action_type = $type;
            $contact->next_action_date = $date->toDateString();
            $contact->next_action_time = $time;
            $contact->next_action_label = $label;
            $contact->save();

            return CrmContactEvent::create([
                'organization_id' => $contact->organization_id,
                'crm_contact_id' => $contact->id,
                'type' => CrmContactEvent::TYPE_NEXT_ACTION_PLANNED,
                'author_user_id' => $actor->id,
                'occurred_at' => now(),
                'payload' => ['action_type' => $type, 'date' => $date->toDateString(), 'time' => $time, 'label' => $label],
            ]);
        });
    }

    /**
     * Retourne null si rien n'etait prevu (rien n'est ecrit).
     */
    public function complete(CrmContact $contact, User $actor): ?CrmContactEvent
    {
        if (! $contact->hasNextAction()) {
            return null;
        }

        $this->guardActor($contact, $actor);

        return DB::transaction(function () use ($contact, $actor) {
            $done = [
                'action_type' => $contact->next_action_type,
                'date' => $contact->next_action_date?->toDateString(),
                'time' => $contact->nextActionTime(),
                'label' => $contact->next_action_label,
            ];

            $contact->next_action_type = null;
            $contact->next_action_date = null;
            $contact->next_action_time = null;
            $contact->next_action_label = null;
            $contact->save();

            return CrmContactEvent::create([
                'organization_id' => $contact->organization_id,
                'crm_contact_id' => $contact->id,
                'type' => CrmContactEvent::TYPE_NEXT_ACTION_DONE,
                'author_user_id' => $actor->id,
                'occurred_at' => now(),
                'payload' => $done,
            ]);
        });
    }

    private function cleanTime(?string $time): ?string
    {
        $time = trim((string) $time);

        if ($time === '') {
            return null;
        }

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new LogicException("A CRM next action time must be HH:MM, got [{$time}].");
        }

        return $time;
    }

    private function guardActor(CrmContact $contact, User $actor): void
    {
        if ($actor->is_admin) {
            return;
        }

        if ($actor->organization_id !== $contact->organization_id) {
            throw new LogicException('A CRM next action can only be planned by a member of the contact Organization.');
        }
    }
}
