<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * TASK-1847 — signal « le compteur GLOBAL de messages non lus de ce
 * destinataire a change », rien de plus.
 *
 * ## A NE JAMAIS CONFONDRE avec `MemberNotificationCreated` (TASK-1843)
 *
 * Deux compteurs DISTINCTS, deux evenements distincts : celui-ci porte sur
 * `messages.read_at` (`User::unreadMessagesCount()`), l'autre sur
 * `member_notifications` (`User::unreadNotificationsCount()`). Un message
 * et une notification interne n'ont ni la meme source de verite, ni la
 * meme portee Organization (`unreadMessagesCount()` n'en a AUCUNE — un
 * membre present dans deux Organizations y voit un compte agrege, voir
 * `App\Models\User::unreadMessagesCount()`), ni le meme canal de lecture
 * HTTP. Les deux partagent neanmoins le MEME canal de diffusion,
 * `user.{userId}` (TASK-1843), deja durci et reutilise tel quel.
 *
 * ## Le payload est VIDE par construction (mandat MASTER)
 *
 * Meme doctrine que `MemberNotificationCreated` : le client relit le
 * compte depuis sa source de verite serveur
 * (`GET /messages/unread-count`), jamais depuis ce payload — ce qui rend
 * aussi la resynchronisation apres coupure triviale (un evenement manque
 * ne desynchronise rien, la prochaine relecture HTTP rend l'etat COMPLET
 * courant).
 *
 * ## `$afterCommit` n'est PAS pose a `true` ici
 *
 * Meme motif que `MemberNotificationCreated` : `App\Support\Messaging\
 * UnreadCountBroadcaster` ne dispatche cet event qu'a l'INTERIEUR d'un
 * callback `DB::afterCommit()` qu'il enregistre lui-meme — donc toujours
 * APRES le commit REEL, quelle que soit la profondeur de transaction de
 * l'appelant.
 */
class MessageUnreadCountChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $recipientId;

    public function __construct(string $recipientId)
    {
        $this->recipientId = $recipientId;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->recipientId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.unread-count-changed';
    }

    public function broadcastWith(): array
    {
        // Volontairement vide — voir le docblock de la classe.
        return [];
    }
}
