<?php

namespace App\Support\Messaging;

use App\Events\MessageUnreadCountChanged;
use App\Models\Message;
use App\Models\Transaction;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1847 — point d'entree UNIQUE pour annoncer « le compteur GLOBAL de
 * messages non lus de ce destinataire a change », apres commit SQL reel.
 *
 * Deux evenements metier font varier ce compteur, et chacun a sa PROPRE
 * methode ici plutot qu'une seule generique : determiner le ou les
 * destinataires n'obeit pas a la meme regle dans les deux cas.
 *
 * ## Creation d'un message : qui voit son compteur augmenter ?
 *
 * `User::unreadMessagesCount()` exclut les messages dont `sender_id`
 * vaut l'identifiant du lecteur (`sender_id != $this->id`) : un message
 * ENVOYE par quelqu'un n'est jamais compte comme non lu POUR LUI-MEME.
 * Deux cas :
 *
 * - message UTILISATEUR (`sender_id` renseigne) : seul l'AUTRE participant
 *   voit son compteur augmenter — l'expediteur, lui, n'a rien a lire.
 * - message SYSTEME (`sender_id` NULL, TASK-1693) : `NULL != 'x'` ne vaut
 *   jamais vrai en SQL, mais `whereNull('sender_id')` matche directement —
 *   LES DEUX participants le voient donc comme non lu, et les DEUX
 *   compteurs doivent etre notifies.
 *
 * ## Lecture (`markRead()`) : qui voit son compteur diminuer ?
 *
 * REVUE MASTER (correctif avant fusion) — pas TOUJOURS le seul lecteur.
 * `messages.read_at` est un champ PARTAGE (aucun modele de lecture par
 * utilisateur) : marquer un message SYSTEME comme lu le rend lu pour LES
 * DEUX participants en une seule ecriture, puisqu'il comptait deja comme
 * non lu pour les deux. `diffuserApresLecture()` accepte donc UN SEUL
 * destinataire par appel (elle ne determine rien elle-meme), mais
 * `MessageThread::markRead()` peut desormais l'appeler DEUX fois — le
 * lecteur, PUIS l'autre participant si (et seulement si) un message
 * systeme figurait parmi les lignes reellement marquees.
 *
 * ## `DB::afterCommit()` pose ICI, a la main — meme doctrine que
 * `ConversationBroadcaster` (TASK-1845) et `NotificationEmitter`
 * (TASK-1843)
 *
 * Chacun des appelants (quatre chemins d'ecriture de message, plus
 * `MessageThread::markRead()`) a sa PROPRE profondeur de transaction (ou
 * aucune). `DB::afterCommit()` ne se declenche qu'au commit REEL, quelle
 * que soit cette profondeur. Le `try/catch` vit A L'INTERIEUR du callback
 * differe : une panne Reverb a ce moment-la ne peut donc jamais faire
 * echouer la transaction qui vient de reussir, ni le message/la lecture
 * deja enregistre, ni sa notification email.
 */
final class UnreadCountBroadcaster
{
    /**
     * A appeler APRES chaque creation reelle de `Message` (les quatre
     * chemins identifies par l'audit TASK-1845/1847), JAMAIS apres une
     * simple edition/epinglage/reaction (TASK-1846) : ces mutations ne
     * changent aucun `read_at`, donc jamais le compte.
     */
    public static function diffuserApresCreationMessage(Message $message, Transaction $transaction): void
    {
        if (! config('messages_unread_realtime.enabled')) {
            return;
        }

        $destinataires = $message->sender_id === null
            ? [$transaction->buyer_id, $transaction->seller_id]
            : [$transaction->buyer_id === $message->sender_id ? $transaction->seller_id : $transaction->buyer_id];

        foreach (array_unique($destinataires) as $destinataireId) {
            self::diffuser($destinataireId, $message->id);
        }
    }

    /**
     * A appeler APRES un `markRead()` qui a REELLEMENT modifie au moins
     * une ligne — jamais inconditionnellement : un appel qui ne change
     * rien ne doit declencher aucun evenement (mandat MASTER).
     */
    public static function diffuserApresLecture(string $lecteurId): void
    {
        if (! config('messages_unread_realtime.enabled')) {
            return;
        }

        self::diffuser($lecteurId, null);
    }

    private static function diffuser(string $destinataireId, ?string $messageId): void
    {
        DB::afterCommit(function () use ($destinataireId, $messageId) {
            try {
                broadcast(new MessageUnreadCountChanged($destinataireId));
            } catch (BroadcastException $e) {
                Log::warning(
                    'TASK-1847 diffusion Reverb en echec (compteur de messages non lus), ecriture deja enregistree.',
                    [
                        'recipient_id' => $destinataireId,
                        'message_id' => $messageId,
                        'raison' => $e->getMessage(),
                    ]
                );
            }
        });
    }
}
