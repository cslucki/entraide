<?php

namespace App\Services\Workshops;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\GuestVisitor;
use App\Models\User;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Crm\WorkshopCrmBridge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TASK-1453 — Le geste canonique « je confirme ma participation » (Growth V3
 * §10/§11, MASTER Q78).
 *
 * Conditions, verifiees ICI (le controller a deja rendu 404/403) :
 *  - User avec email VERIFIE, de la MEME Organization que la session ;
 *  - session PUBLIEE, A VENIR, d'un atelier PUBLIE ;
 *  - capacite controlee AU MOMENT DE L'INSCRIPTION (compte des inscrits
 *    `registered` < capacity), sous verrou de ligne : deux onglets ne
 *    depassent pas la capacite ;
 *  - une inscription par (session, User) : rejeu = la meme ligne ;
 *    re-inscription apres annulation = reactivation, jamais une seconde ligne.
 *
 * Provenance : le visiteur Guest rattache au compte (claim SW-11) et sa
 * Journey exacte sont recopies sur l'inscription ; l'Interest Guest n'est
 * JAMAIS efface. Journal : `participation_confirmed` (dedupe par inscription,
 * jamais reecrit a la reactivation) et `converted` si la Journey EXACTE
 * attribuee a l'inscription a pour contrat `workshop_participation` (dedupe
 * journey + user : une Journey = une conversion de ce User).
 */
final class WorkshopRegistrationService
{
    public function __construct(
        private readonly AcquisitionEventRecorder $events,
        private readonly WorkshopCrmBridge $crm,
    ) {}

    public function register(WorkshopSession $session, User $user): WorkshopRegistration
    {
        $this->guard($session, $user);
        $visitor = $this->claimedVisitor($session, $user);

        $registration = DB::transaction(function () use ($session, $user, $visitor): WorkshopRegistration {
            // Verrou sur la session : le comptage et l'ecriture forment un seul moment canonique.
            $locked = WorkshopSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $existing = WorkshopRegistration::query()->where('workshop_session_id', $locked->getKey())->where('user_id', $user->getKey())->first();
            if ($existing !== null && $existing->isRegistered()) {
                return $existing;
            }

            if ($locked->capacity !== null) {
                $registered = WorkshopRegistration::query()->where('workshop_session_id', $locked->getKey())->registered()->count();
                if ($registered >= $locked->capacity) {
                    throw new WorkshopSessionFull($locked);
                }
            }

            if ($existing !== null) {
                $existing->forceFill(['status' => WorkshopRegistration::STATUS_REGISTERED, 'registered_at' => now(), 'cancelled_at' => null])->save();

                return $existing;
            }

            try {
                return WorkshopRegistration::query()->create([
                    'organization_id' => $locked->organization_id,
                    'workshop_id' => $locked->workshop_id,
                    'workshop_session_id' => $locked->getKey(),
                    'user_id' => $user->getKey(),
                    'guest_visitor_id' => $visitor?->getKey(),
                    'acquisition_journey_id' => $visitor?->acquisition_journey_id,
                    'status' => WorkshopRegistration::STATUS_REGISTERED,
                    'registered_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return WorkshopRegistration::query()->where('workshop_session_id', $locked->getKey())->where('user_id', $user->getKey())->firstOrFail();
            }
        });

        rescue(function () use ($registration, $session, $user, $visitor): void {
            $dimensions = ($visitor === null ? [] : $this->events->visitorDimensions($visitor)) + ['user' => $user, 'locale' => app()->getLocale()];
            $metadata = ['workshop' => $session->workshop->slug, 'session' => $session->getKey()];
            $this->events->record($session->organization, AcquisitionEvent::PARTICIPATION_CONFIRMED, $dimensions, $metadata, AcquisitionEvent::PARTICIPATION_CONFIRMED.':registration:'.$registration->getKey());

            // MASTER Q80 : converted SEULEMENT si la Journey EXACTE attribuee a CETTE inscription (reprise du visiteur claime)
            // a pour contrat workshop_participation — jamais deduit de la Journey de l'atelier ; une Journey = une conversion de ce User.
            $journey = $registration->acquisition_journey_id === null ? null : AcquisitionJourney::query()->find($registration->acquisition_journey_id);
            if ($journey instanceof AcquisitionJourney && $journey->conversion_goal === AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION) {
                $this->events->record($session->organization, AcquisitionEvent::CONVERTED, ['journey' => $journey] + $dimensions, ['goal' => $journey->conversion_goal] + $metadata, AcquisitionEvent::CONVERTED.':journey:'.$journey->getKey().':user:'.$user->getKey());
            }
        });

        // TASK-1457 (Growth V3 §15, Mini-CRM V2 §10) : le moment produit pertinent — le CRM retrouve/cree/lie le Contact.
        // Sous rescue() et HORS de la transaction de capacite : le CRM ne decide jamais d'une inscription.
        rescue(fn () => $this->crm->onParticipationConfirmed($registration->fresh()));

        return $registration;
    }

    public function cancel(WorkshopSession $session, User $user): ?WorkshopRegistration
    {
        $registration = WorkshopRegistration::query()->where('workshop_session_id', $session->getKey())->where('user_id', $user->getKey())->first();
        if ($registration === null || ! $registration->isRegistered()) {
            return $registration;
        }

        $registration->forceFill(['status' => WorkshopRegistration::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        return $registration;
    }

    /** Les sessions d'un atelier auxquelles ce User est inscrit (page publique, etat membre). */
    public function registeredSessionIds(User $user, string $workshopId): array
    {
        return WorkshopRegistration::query()->where('user_id', $user->getKey())->where('workshop_id', $workshopId)->registered()->pluck('workshop_session_id')->all();
    }

    /** Les sessions de cet atelier choisies EN GUEST par les visiteurs rattaches a ce compte (claim SW-11) : mises en evidence, jamais converties sans geste. */
    public function guestSelectedSessionIds(User $user, string $workshopId): array
    {
        return WorkshopSessionInterest::query()
            ->where('workshop_id', $workshopId)
            ->selected()
            ->whereIn('guest_visitor_id', GuestVisitor::query()->where('claimed_user_id', $user->getKey())->select('id'))
            ->pluck('workshop_session_id')
            ->all();
    }

    /**
     * Le visiteur Guest rattache a ce compte dans cette Organization (claim SW-11), s'il existe — jamais efface.
     * TASK-1459 (audit F7, MASTER #52) : l'autorite de provenance d'une inscription est, dans l'ordre,
     *  1. le visiteur rattache qui PORTE L'INTERET de cette session (la causalite la plus forte :
     *     visiteur -> choisit la session -> cree/verifie son compte -> confirme la session) ;
     *  2. sinon le PREMIER visiteur acquis par ce compte dans cette Organization (first touch :
     *     `first_seen_at`, puis id — jamais `claimed_at`, qui date le rattachement, pas l'acquisition) ;
     *  3. sinon aucune provenance. Jamais « le dernier visiteur rattache ».
     */
    private function claimedVisitor(WorkshopSession $session, User $user): ?GuestVisitor
    {
        $claimed = GuestVisitor::query()->where('organization_id', $session->organization_id)->where('claimed_user_id', $user->getKey());
        $holder = (clone $claimed)
            ->whereIn('id', WorkshopSessionInterest::query()->where('workshop_session_id', $session->getKey())->select('guest_visitor_id'))
            ->orderBy('first_seen_at')->orderBy('id')->first();

        return $holder ?? (clone $claimed)->orderBy('first_seen_at')->orderBy('id')->first();
    }

    private function guard(WorkshopSession $session, User $user): void
    {
        if ($user->email_verified_at === null) {
            throw new LogicException('Only a verified user can confirm a participation.');
        }
        if ((string) $session->organization_id !== (string) $user->organization_id) {
            throw new LogicException('A registration stays inside the user organization.');
        }
        if (! $session->isPublished() || $session->isPast() || ! $session->workshop->isPublished()) {
            throw new LogicException('Only a published upcoming session of a published workshop accepts registrations.');
        }
    }
}
