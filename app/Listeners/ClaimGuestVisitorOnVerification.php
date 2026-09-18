<?php

namespace App\Listeners;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\User;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Crm\ShellCrmBridge;
use App\Services\GuestShell\GuestClaimService;
use Illuminate\Auth\Events\Verified;

/**
 * TASK-1445 — SW-11 : a la verification de l'email, le visiteur pseudonyme du
 * Shell Welcome de la MEME Organization est rattache au compte (V3 §22).
 *
 * Decouvert automatiquement (app/Listeners). Le cookie `bp_guest` est celui
 * de la requete de verification (le clic sur le lien, dans le meme navigateur) ;
 * sans cookie, ou avec le cookie d'un autre tenant, rien ne se passe et rien
 * n'est revele. Un echec du claim ne casse JAMAIS une verification (`rescue()`).
 *
 * TASK-1449 (Growth V3 §5, MASTER Q77) : le fait `email_verified` est journalise
 * une fois par User, avec l'attribution FIRST TOUCH du visiteur rattache s'il y
 * en a un. `converted` n'est PAS un raccourci de la verification : c'est le
 * contrat canonique de la Journey (`conversion_goal`) — `account` est atteint
 * ici ; `workshop_participation` et `contact` le seront par leurs TASKs. Sans
 * Journey, aucun contrat, aucune conversion.
 */
class ClaimGuestVisitorOnVerification
{
    public function __construct(
        private readonly GuestClaimService $claims,
        private readonly AcquisitionEventRecorder $events,
        private readonly ShellCrmBridge $crm,
    ) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $user->organization_id === null) {
            return;
        }

        $visitor = rescue(fn () => $this->claims->claim($user, request()));

        rescue(function () use ($user, $visitor): void {
            $organization = Organization::query()->find($user->organization_id);
            if ($organization === null) {
                return;
            }

            $dimensions = ($visitor === null ? [] : $this->events->visitorDimensions($visitor)) + ['user' => $user, 'locale' => app()->getLocale()];
            $this->events->record($organization, AcquisitionEvent::EMAIL_VERIFIED, $dimensions, [], AcquisitionEvent::EMAIL_VERIFIED.':user:'.$user->getKey());

            $journey = $dimensions['journey'] ?? null;
            if ($visitor !== null && $journey instanceof AcquisitionJourney && $journey->conversion_goal === AcquisitionJourney::GOAL_ACCOUNT) {
                $this->events->record($organization, AcquisitionEvent::CONVERTED, $dimensions, ['goal' => $journey->conversion_goal], AcquisitionEvent::CONVERTED.':journey:'.$journey->getKey().':user:'.$user->getKey());
            }
        });

        // TASK-1458 (Shell Welcome V3 §23 SW-12, Mini-CRM V2 §9, Growth V3 §16) : le claim est LE moment produit pertinent —
        // le Contact du membre est retrouve/cree/lie une fois, jamais a chaque message. Un CRM en panne ne casse jamais la verification.
        if ($visitor !== null) {
            rescue(fn () => $this->crm->onClaim($visitor, $user));
        }
    }
}
