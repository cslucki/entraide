<?php

namespace App\Listeners;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\User;
use App\Services\Crm\CrmTimelineService;
use Illuminate\Auth\Events\Verified;

/**
 * TASK-1415 — « email verifie » entre dans la timeline du Contact relie.
 *
 * Decouvert automatiquement (app/Listeners). L'Organization est celle du
 * membre verifie : on cherche le Contact relie a CE user dans CETTE
 * Organization ; s'il n'y en a pas, rien n'est fait — et on ne cree JAMAIS
 * de Contact depuis ici.
 *
 * `rescue()` : un echec cote CRM ne casse jamais une verification, mais il
 * n'est pas avale : `rescue()` REPORTE l'exception au handler (comportement
 * par defaut, meme pattern que la bienvenue T1043 et la verification T1412).
 */
class RecordCrmEmailVerified
{
    public function __construct(private readonly CrmTimelineService $timeline) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->organization_id === null) {
            return;
        }

        rescue(function () use ($user) {
            $contact = CrmContact::forOrganization($user->organization_id)->where('user_id', $user->id)->first();

            if ($contact !== null) {
                $this->timeline->recordOnce($contact, CrmContactEvent::TYPE_EMAIL_VERIFIED);
            }
        });
    }
}
