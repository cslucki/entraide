<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * TASK-1845 — signal « un message vient d'etre enregistre dans cette
 * conversation », rien de plus.
 *
 * ## Le payload est VIDE par construction (mandat MASTER)
 *
 * Aucune donnee personnelle, aucun corps de message, aucune piece jointe.
 * Seul le canal (deja scope a la conversation par `routes/channels.php`)
 * porte le signal. Le jour ou une reception navigateur existera (hors
 * perimetre de cette TASK), le client devra relire la conversation depuis
 * sa source de verite serveur — jamais depuis ce payload. Meme doctrine que
 * `MemberNotificationCreated` (TASK-1843).
 *
 * ## `$afterCommit` n'est PAS pose a `true` ici
 *
 * Meme motif que `MemberNotificationCreated` : `ConversationBroadcaster::
 * diffuserApresCommit()` ne dispatche cet event qu'a l'INTERIEUR d'un
 * callback `DB::afterCommit()` qu'il enregistre lui-meme — donc toujours
 * APRES le commit REEL, quel que soit l'appelant (`MessageThread::
 * sendMessage()`, `MessageSysteme::ecrire()`, `ServiceRequestLifecycle::
 * markHandled()`, `MessageController`). Si cette propriete valait `true`
 * ici, `BroadcastEvent::__construct()` chercherait une transaction encore
 * ouverte pour differer une SECONDE fois — qui n'existe plus a ce stade —
 * et se contenterait de dispatcher immediatement : sans effet, mais
 * trompeur a lire.
 */
class ConversationMessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $transactionId;

    public function __construct(Message $message)
    {
        $this->transactionId = $message->transaction_id;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->transactionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.created';
    }

    public function broadcastWith(): array
    {
        // Volontairement vide — voir le docblock de la classe.
        return [];
    }
}
