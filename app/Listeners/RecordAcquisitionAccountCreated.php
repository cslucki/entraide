<?php

namespace App\Listeners;

use App\Models\AcquisitionEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Auth\Events\Registered;

/**
 * TASK-1449 — `account_created` (Growth V3 §5) : a l'inscription, le fait est
 * journalise avec, si le navigateur porte le cookie Guest de la MEME
 * Organization, la provenance FIRST TOUCH du visiteur (Journey exacte, UTM,
 * shortcut) — l'attribution survit Guest → User. Sans cookie, ou avec le cookie
 * d'un autre tenant : le fait est journalise sans visiteur, rien n'est revele.
 *
 * Lecture pure (`find()`, jamais `ensure()`) : l'inscription ne cree aucune
 * identite Guest. Deduplique par User (rejeu de `Registered` = une ligne).
 * Un echec du journal ne casse JAMAIS une inscription (`rescue()`).
 */
class RecordAcquisitionAccountCreated
{
    public function __construct(
        private readonly AcquisitionEventRecorder $recorder,
        private readonly GuestVisitorResolver $visitors,
    ) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $user->organization_id === null) {
            return;
        }

        rescue(function () use ($user): void {
            $organization = Organization::query()->find($user->organization_id);
            if ($organization === null) {
                return;
            }

            $visitor = $this->visitors->find(request(), $organization);
            $dimensions = $visitor === null ? [] : $this->recorder->visitorDimensions($visitor);

            $this->recorder->record(
                $organization,
                AcquisitionEvent::ACCOUNT_CREATED,
                $dimensions + ['user' => $user, 'locale' => app()->getLocale()],
                [],
                AcquisitionEvent::ACCOUNT_CREATED.':user:'.$user->getKey(),
            );
        });
    }
}
