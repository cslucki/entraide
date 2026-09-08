<?php

namespace App\Services\Workshops;

use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSessionInterest;

/**
 * TASK-1453 — La reprise du parcours (Growth V3 §11, MASTER Q80) : un visiteur
 * qui a CHOISI une session puis cree un compte doit, une fois son email
 * verifie, revenir a l'atelier choisi — pas a un tableau de bord generique.
 *
 * Mecanisme minimal, dans l'esprit de `InvitationResumption` : quand le Guest
 * ENGAGE la creation de compte (POST d'inscription) et que son cookie de la
 * MEME Organization porte un interet selectionne, une REFERENCE STRUCTUREE
 * (organization_id, workshop_id, workshop_session_id) est parquee en session —
 * jamais une URL brute du navigateur. Apres la verification, la reference est
 * relue, REVALIDEE (User de cette Organization, atelier publie de ce tenant),
 * la route interne est reconstruite avec route(), la cle est consommee.
 * Autre navigateur : pas de session, repli Laravel normal (comme le claim).
 */
final class WorkshopResumption
{
    public const SESSION_KEY = 'workshop.resume';

    /** @return array{organization_id: string, workshop_id: string, workshop_session_id: string}|null */
    public function park(Organization $organization, ?GuestVisitor $visitor): ?array
    {
        if ($visitor === null || (string) $visitor->organization_id !== (string) $organization->getKey()) {
            return null;
        }

        $interest = WorkshopSessionInterest::query()
            ->where('guest_visitor_id', $visitor->getKey())
            ->where('organization_id', $organization->getKey())
            ->selected()
            ->orderByDesc('selected_at')
            ->first();
        if ($interest === null) {
            return null;
        }

        $reference = [
            'organization_id' => (string) $organization->getKey(),
            'workshop_id' => (string) $interest->workshop_id,
            'workshop_session_id' => (string) $interest->workshop_session_id,
        ];
        session()->put(self::SESSION_KEY, $reference);

        return $reference;
    }

    /** La destination reconstruite et revalidee pour CE User, consommee une fois ; null = repli normal. */
    public function consume(User $user): ?string
    {
        $reference = session()->pull(self::SESSION_KEY);
        if (! is_array($reference) || ! isset($reference['organization_id'], $reference['workshop_id'])) {
            return null;
        }
        if ((string) $user->organization_id !== (string) $reference['organization_id']) {
            return null;
        }

        $organization = Organization::query()->find($reference['organization_id']);
        if ($organization === null || ! $organization->is_active || ! $organization->is_public) {
            return null;
        }

        $workshop = Workshop::query()->forOrganization($organization)->published()->whereKey($reference['workshop_id'])->first();
        if ($workshop === null) {
            return null;
        }

        return route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $workshop->slug]);
    }
}
