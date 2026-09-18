<?php

namespace App\Services\Workshops;

use App\Models\AcquisitionEvent;
use App\Models\GuestVisitor;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\Acquisition\AcquisitionEventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * TASK-1452 — B4-B : le geste Guest « je choisis cette session » (Growth V3
 * §10, MASTER Q78).
 *
 * Regles :
 *  - la session est PUBLIEE, A VENIR, d'un atelier PUBLIE, dans l'Organization
 *    du visiteur — sinon faute (le controller a deja rendu 404 ; ici la garde
 *    de coherence tenant) ;
 *  - interet != inscription : aucun User, aucune place consommee, aucune
 *    reservation ; la capacite reste informative ;
 *  - idempotent : une ligne par (session, visiteur) ; re-selectionner reactive
 *    une ligne retiree ; la contrainte unique tranche la course ;
 *  - le journal porte `session_selected` (dedupe par interet : un fait par
 *    ligne, pas par clic) avec l'attribution first touch du visiteur — sous
 *    rescue() : la telemetrie ne casse jamais le geste.
 */
final class WorkshopInterestService
{
    public function __construct(private readonly AcquisitionEventRecorder $events) {}

    public function select(WorkshopSession $session, GuestVisitor $visitor): WorkshopSessionInterest
    {
        $this->guard($session, $visitor);

        $interest = WorkshopSessionInterest::query()->where('workshop_session_id', $session->getKey())->where('guest_visitor_id', $visitor->getKey())->first();

        if ($interest === null) {
            try {
                $interest = WorkshopSessionInterest::query()->create([
                    'organization_id' => $session->organization_id,
                    'workshop_id' => $session->workshop_id,
                    'workshop_session_id' => $session->getKey(),
                    'guest_visitor_id' => $visitor->getKey(),
                    'status' => WorkshopSessionInterest::STATUS_SELECTED,
                    'selected_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Deux onglets : la premiere ligne a gagne.
                $interest = WorkshopSessionInterest::query()->where('workshop_session_id', $session->getKey())->where('guest_visitor_id', $visitor->getKey())->firstOrFail();
            }
        } elseif (! $interest->isSelected()) {
            $interest->forceFill(['status' => WorkshopSessionInterest::STATUS_SELECTED, 'selected_at' => now(), 'withdrawn_at' => null])->save();
        }

        rescue(fn () => $this->events->record(
            $session->organization,
            AcquisitionEvent::SESSION_SELECTED,
            $this->events->visitorDimensions($visitor),
            ['workshop' => $session->workshop->slug, 'session' => $session->getKey()],
            AcquisitionEvent::SESSION_SELECTED.':interest:'.$interest->getKey(),
        ));

        return $interest;
    }

    public function withdraw(WorkshopSession $session, GuestVisitor $visitor): ?WorkshopSessionInterest
    {
        $this->guard($session, $visitor);

        $interest = WorkshopSessionInterest::query()->where('workshop_session_id', $session->getKey())->where('guest_visitor_id', $visitor->getKey())->first();
        if ($interest === null || ! $interest->isSelected()) {
            return $interest;
        }

        $interest->forceFill(['status' => WorkshopSessionInterest::STATUS_WITHDRAWN, 'withdrawn_at' => now()])->save();

        return $interest;
    }

    /** L'interet courant d'un visiteur pour les sessions d'un atelier, par session (page publique). */
    public function selectedSessionIds(GuestVisitor $visitor, string $workshopId): array
    {
        return WorkshopSessionInterest::query()
            ->where('guest_visitor_id', $visitor->getKey())
            ->where('workshop_id', $workshopId)
            ->selected()
            ->pluck('workshop_session_id')
            ->all();
    }

    private function guard(WorkshopSession $session, GuestVisitor $visitor): void
    {
        if ((string) $session->organization_id !== (string) $visitor->organization_id) {
            throw new LogicException('A guest interest stays inside the visitor organization.');
        }
        if (! $session->isPublished() || $session->isPast() || ! $session->workshop->isPublished()) {
            throw new LogicException('Only a published upcoming session of a published workshop can be selected.');
        }
    }
}
