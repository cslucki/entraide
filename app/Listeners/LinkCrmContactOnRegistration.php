<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Crm\CrmContactService;
use Illuminate\Auth\Events\Registered;

/**
 * TASK-1413 — a l'inscription, le prospect retrouve son Contact.
 *
 * Decouvert automatiquement (app/Listeners). L'Organization du nouveau membre
 * est DETERMINISTE ici : `RegisteredUserController` pose `organization_id`
 * avant d'emettre `Registered`. Aucun contexte tenant n'est invente — sans
 * `organization_id`, rien n'est fait.
 *
 * Un echec cote CRM ne casse JAMAIS une inscription (`rescue()`), comme les
 * emails de bienvenue (T1043) et de verification (T1412).
 */
class LinkCrmContactOnRegistration
{
    public function __construct(private readonly CrmContactService $contacts) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->organization_id === null) {
            return;
        }

        rescue(fn () => $this->contacts->linkOnRegistration($user));
    }
}
