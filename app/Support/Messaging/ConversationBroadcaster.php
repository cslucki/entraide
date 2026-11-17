<?php

namespace App\Support\Messaging;

use App\Events\ConversationMessageCreated;
use App\Models\Message;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1845 — point d'entree UNIQUE pour annoncer « un message vient d'etre
 * ecrit dans cette conversation », apres commit SQL reel.
 *
 * ## Pourquoi un point d'entree unique, pas un event direct a chaque site
 *
 * L'audit prealable (section 1 du mandat) a denombre QUATRE chemins
 * d'ecriture reels de `Message` pour la messagerie privee : `MessageThread::
 * sendMessage()` (deja durci, TASK-1844), `MessageSysteme::ecrire()`
 * (messages systeme d'entraide, appele par `TransactionController` et son
 * equivalent API), `ServiceRequestLifecycle::markHandled()` (un message
 * systeme PAR transaction concurrente refusee, dans une boucle) et
 * `MessageController` (premiere ligne « Conversation directe demarree »).
 * Repeter `DB::afterCommit()` + le `try/catch(BroadcastException)` a
 * chacun aurait expose exactement le risque que le mandat nomme : une
 * emission oubliee ou dupliquee le jour ou un cinquieme chemin apparait.
 * Un seul point d'appel, invoque juste apres chaque `Message::create()`,
 * ferme ce risque une fois pour toutes les quatre.
 *
 * Les messages SYSTEME sont couverts au meme titre que les messages
 * utilisateur : le signal annonce que LA CONVERSATION a change, pas que
 * quelqu'un a ecrit — un changement de statut du a un tiers (offre
 * concurrente acceptee) doit resynchroniser les deux parties tout autant
 * qu'un message tape au clavier.
 *
 * ## `DB::afterCommit()` pose ICI, a la main — meme motif que TASK-1843
 *
 * Chacun des quatre appelants a sa PROPRE profondeur de transaction (ou
 * aucune). `DB::afterCommit()` ne se declenche qu'au commit REEL, quelle
 * que soit cette profondeur — garantie deja demontree par
 * `NotificationEmitter::diffuserSiActive()`. Le `try/catch` vit A
 * L'INTERIEUR du callback differe : une panne Reverb a ce moment-la ne peut
 * donc jamais faire echouer la transaction qui vient de reussir, ni le
 * message deja enregistre, ni sa notification email (TASK-1844), ni aucun
 * autre callback `afterCommit` enregistre a cote.
 */
final class ConversationBroadcaster
{
    public static function diffuserApresCommit(Message $message): void
    {
        if (! config('conversation_realtime.enabled')) {
            return;
        }

        DB::afterCommit(function () use ($message) {
            try {
                broadcast(new ConversationMessageCreated($message));
            } catch (BroadcastException $e) {
                Log::warning(
                    'TASK-1845 diffusion Reverb en echec, message de conversation deja enregistre.',
                    [
                        'message_id' => $message->id,
                        'transaction_id' => $message->transaction_id,
                        'raison' => $e->getMessage(),
                    ]
                );
            }
        });
    }
}
