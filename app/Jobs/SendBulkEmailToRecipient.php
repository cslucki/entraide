<?php

namespace App\Jobs;

use App\Services\BulkEmailSendService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TASK-1861 — livrer UN destinataire d'un envoi groupe (`BulkEmailSend`)
 * hors du thread HTTP de `AdminEmailBulkSendingController::sendBatch()`.
 *
 * ## Perimetre — uniquement le destinataire DEJA reserve
 *
 * Ce job n'existe que pour un destinataire dont `BulkEmailSendService::
 * claimRecipient()` a DEJA fait passer le statut de `pending` a `queued`,
 * par une UNIQUE requete UPDATE conditionnee sur l'etat attendu — c'est
 * CETTE reservation, pas ce job, qui empeche le double envoi en cas de
 * declenchements concurrents. Le job se contente d'executer le travail
 * d'UN destinataire deja possede.
 *
 * ## La charge utile est un IDENTIFIANT, rien d'autre
 *
 * `recipientId` — meme motif que toute la famille
 * (`SendTransactionStatusChangedEmail`/`SendNewMessageReceivedEmail`/
 * `SendLoopInvitationEmail`). Le worker relit le destinataire, l'envoi
 * parent et l'utilisateur FRAIS : `BulkEmailSendService::processRecipient()`
 * porte toute la logique (gabarits, variables, replyTo, journal), ce job
 * ne fait que l'invoquer.
 *
 * ## `$tries = 1` — meme doctrine que toute la famille
 *
 * Aucune reprise automatique : un transport qui leve APRES avoir ete
 * sollicite laisse le destinataire `ambiguous` (voir le docblock de
 * `BulkEmailSendRecipient`), jamais rejoue aveuglement — cela risquerait un
 * second envoi sur une panne transitoire du transport.
 */
class SendBulkEmailToRecipient implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-bulk-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $recipientId,
    ) {
        $this->onQueue(self::DEDICATED_QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(BulkEmailSendService $service): void
    {
        $service->processRecipient($this->recipientId);
    }
}
