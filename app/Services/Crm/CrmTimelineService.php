<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TASK-1415 — CRM-3 : la timeline d'un Contact, et les notes qu'on y ecrit.
 *
 * Trois regles :
 * - APPEND-FIRST, strictement en V1 : une note ne s'edite ni ne se supprime ;
 *   une correction est une nouvelle note (CDC §6 : « aucune disparition
 *   silencieuse ») ;
 * - TENANT : l'auteur d'une note est un membre de l'Organization du Contact
 *   (ou un admin plateforme) ; sinon refus, rien n'est ecrit ;
 * - « DERNIER CONTACT » garde son sens commercial : `last_interaction_at`
 *   n'avance que sur une interaction REELLE avec la personne (note a canal),
 *   jamais sur un fait systeme (statut, compte cree, email verifie).
 */
class CrmTimelineService
{
    public const CHANNELS = ['call', 'meeting', 'email', 'whatsapp', 'sms', 'other'];

    public const MAX_NOTE_LENGTH = 5000;

    public function addNote(CrmContact $contact, string $body, User $author, ?string $channel = null): CrmContactEvent
    {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::MAX_NOTE_LENGTH) {
            throw new LogicException('A CRM note must be 1 to '.self::MAX_NOTE_LENGTH.' characters.');
        }

        if ($channel !== null && ! in_array($channel, self::CHANNELS, true)) {
            throw new LogicException("Unknown CRM interaction channel [{$channel}].");
        }

        $this->guardAuthor($contact, $author);

        return DB::transaction(function () use ($contact, $body, $author, $channel) {
            $now = now();

            if ($channel !== null) {
                $contact->last_interaction_at = $now;
                $contact->save();
            }

            return $this->record($contact, CrmContactEvent::TYPE_NOTE, [
                'body' => $body,
                'channel' => $channel,
            ], $author, $now);
        });
    }

    /**
     * Faits SYSTEME : sans auteur humain, sans effet sur `last_interaction_at`.
     * Idempotent par (contact, type) pour les faits qui n'arrivent qu'une fois
     * dans la vie d'un compte.
     */
    public function recordOnce(CrmContact $contact, string $type, array $payload = []): ?CrmContactEvent
    {
        if (! in_array($type, CrmContactEvent::SYSTEM_TYPES, true)) {
            throw new LogicException("Type [{$type}] is not a system timeline fact.");
        }

        $already = $contact->events()->where('type', $type)->exists();

        if ($already) {
            return null;
        }

        return $this->record($contact, $type, $payload, null, now());
    }

    /**
     * TASK-1417 — un fait « coordonnees modifiees » : quels champs, de quoi a
     * quoi, par qui. Aucun effet sur `last_interaction_at`.
     */
    public function recordContactUpdated(CrmContact $contact, array $changes, User $actor): CrmContactEvent
    {
        $this->guardAuthor($contact, $actor);

        return $this->record($contact, CrmContactEvent::TYPE_CONTACT_UPDATED, ['changes' => $changes], $actor, now());
    }

    public function timeline(CrmContact $contact): Collection
    {
        return $contact->events()->chronological()->get();
    }

    private function record(CrmContact $contact, string $type, array $payload, ?User $author, $occurredAt): CrmContactEvent
    {
        return CrmContactEvent::create([
            'organization_id' => $contact->organization_id,
            'crm_contact_id' => $contact->id,
            'type' => $type,
            'author_user_id' => $author?->id,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function guardAuthor(CrmContact $contact, User $author): void
    {
        if ($author->is_admin) {
            return;
        }

        if ($author->organization_id !== $contact->organization_id) {
            throw new LogicException('A CRM note can only be written by a member of the contact Organization.');
        }
    }
}
