<?php

namespace App\Support\Notifications;

use App\Models\LoopInvitation;
use App\Models\MemberNotification;

/**
 * TASK-1374 — resoudre la CIBLE d'une notification, au moment du clic.
 *
 * ## Rien n'est stocke, tout est resolu
 *
 * Une notification ne porte qu'`object_type` + `object_id`. Aucune URL, aucun
 * titre, aucun contenu. La destination est donc reconstruite ici, cote serveur,
 * **sous les permissions du moment** — jamais lue dans la ligne.
 *
 * C'est ce qui fait qu'une notification vieille d'un mois n'ouvre aucune porte
 * fermee depuis : il n'y a rien d'ancien a reafficher.
 *
 * ## `null` est une reponse, pas un echec
 *
 * Objet supprime, invitation expiree, revoquee, deja acceptee, acces perdu :
 * le resolver rend `null`, et l'ecran le dit honnetement — « Cet element n'est
 * plus accessible. » Il ne devine pas, il ne se rabat pas sur une page
 * approchante, et surtout il ne laisse filtrer aucun fragment de ce que la
 * cible contenait.
 *
 * ## Un type inconnu ne mene nulle part
 *
 * Comme le catalogue, ce resolver est fail-closed : un `object_type` qu'il ne
 * connait pas rend `null`. Ajouter un producteur, c'est ajouter sa branche ici —
 * pas esperer qu'une convention de nommage suffise.
 */
class NotificationTargetResolver
{
    /**
     * L'adresse ou envoyer le membre, ou `null` si la cible ne lui est plus
     * accessible.
     */
    public function resolve(MemberNotification $notification): ?string
    {
        return match ($notification->object_type) {
            NotificationCatalogue::OBJECT_LOOP_INVITATION => $this->loopInvitation($notification),
            default => null,
        };
    }

    /**
     * Une invitation mene la ou l'on peut y REPONDRE.
     *
     * La Boucle elle-meme ne suffirait pas : un non-membre y verrait une page de
     * presentation, sans pouvoir accepter — on ferait cliquer pour rien.
     *
     * Le jeton n'est jamais stocke dans la notification. Il est relu **ici**,
     * sur la ligne d'invitation, apres verification du tenant : la notification
     * ne detient aucune autorite, elle designe seulement un objet.
     */
    private function loopInvitation(MemberNotification $notification): ?string
    {
        if ($notification->object_id === null) {
            return null;
        }

        $invitation = LoopInvitation::query()
            ->whereKey($notification->object_id)
            // La frontiere de tenant est reverifiee ICI aussi. Elle l'etait deja
            // a l'ecriture, mais une notification peut survivre a bien des
            // changements, et une garde qui ne s'applique qu'une fois n'est pas
            // une garde.
            ->where('organization_id', $notification->organization_id)
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            // Expiree, revoquee, deja acceptee, supprimee : la porte est fermee,
            // et on le dit plutot que d'envoyer vers une page qui refusera.
            return null;
        }

        return route('loop-invitations.show', ['token' => $invitation->token]);
    }
}
