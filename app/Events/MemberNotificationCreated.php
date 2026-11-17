<?php

namespace App\Events;

use App\Models\MemberNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * TASK-1843 — signal « une notification interne vient d'etre creee pour ce
 * destinataire », rien de plus.
 *
 * ## Le payload est VIDE par construction (mandat MASTER)
 *
 * Contrairement a `LoopMessageCreated`, cet event ne transmet NI le contenu
 * de la notification NI aucune donnee personnelle — seul le canal (deja
 * scope au destinataire) porte un signal. Le client recoit l'evenement et
 * relit le compte depuis sa source de verite serveur, via
 * `NotificationCenterController::unreadCount()` (authentifie, scope comme
 * le reste du controleur) : jamais depuis ce payload. C'est aussi ce qui
 * rend la resynchronisation idempotente (section 3.5 de l'audit TASK-1842) —
 * un evenement manque pendant une coupure ne desynchronise rien, la
 * prochaine relecture HTTP rend l'etat COMPLET courant.
 *
 * ## `$afterCommit` n'est PAS pose a `true` ici
 *
 * Ce n'est pas un oubli. `NotificationEmitter::emit()` ne dispatche cet
 * event qu'a l'INTERIEUR d'un callback `DB::afterCommit()` qu'il enregistre
 * lui-meme — donc toujours APRES le commit REEL, qu'`emit()` soit la
 * transaction la plus externe ou imbriquee dans celle d'un producteur
 * (mentions, demandes d'aide, invitations...). Si cette propriete valait
 * `true` ici, `BroadcastEvent::__construct()` chercherait une transaction
 * encore ouverte pour differer une SECONDE fois — qui n'existe plus a ce
 * stade — et se contenterait de dispatcher immediatement : sans effet,
 * mais trompeur a lire. Voir `NotificationEmitter::emit()` pour le detail
 * complet de cette garde, Y COMPRIS la protection contre une panne Reverb
 * (`catch (BroadcastException)` autour du SEUL point ou cet event part).
 */
class MemberNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $recipientId;

    public function __construct(MemberNotification $notification)
    {
        $this->recipientId = $notification->recipient_id;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->recipientId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.count-changed';
    }

    public function broadcastWith(): array
    {
        // Volontairement vide — voir le docblock de la classe.
        return [];
    }
}
