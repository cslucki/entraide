<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1858 — livrer `NewMessageReceived` hors du thread HTTP/Livewire,
 * pour l'unique declencheur `MessageThread::notifierApresCommit()`.
 * Audit prealable (TASK-1857) : ce declencheur partage la meme famille de
 * risque que `TransactionStatusChanged` avant TASK-1855/1857 — un
 * `DB::afterCommit()` BRUT reste SYNCHRONE dans le thread HTTP, et
 * bloque sur une conversation SMTP a CHAQUE message prive echange (plus
 * frequent que les transitions de transaction).
 *
 * ## Perimetre
 *
 * Seul `MessageThread::notifierApresCommit()` declenche `NewMessageReceived`
 * aujourd'hui (verifie : un seul appel site dans tout le depot). Rien
 * d'autre a deporter dans la messagerie privee pour cette notification.
 *
 * ## La charge utile est trois IDENTIFIANTS, rien d'autre
 *
 * `transactionId`, `messageId`, `recipientId` — meme motif que
 * `SendTransactionStatusChangedEmail` (TASK-1855/1857). Le worker relit
 * la transaction, le message ET le destinataire FRAIS : aucun objet
 * Eloquent serialise dans la charge utile.
 *
 * ## Autorisation TENANT — meme idiome que TASK-1854/1855/1857
 *
 * `Transaction` porte `BelongsToOrganizationScope` (contrairement a
 * `Message`, qui n'a aucun scope global propre — verifie) : la PREMIERE
 * lecture de la transaction doit explicitement l'ecarter
 * (`withoutGlobalScope`) avant de pouvoir lier le tenant.
 * `withOrganizationContext()` reprend A L'IDENTIQUE l'idiome deja en
 * place dans tous les jobs de cette famille : etat precedent sauvegarde,
 * restaure dans un `finally` meme si la notification leve.
 *
 * ## Isolation explicite du destinataire (meme arbitrage que PR #862)
 *
 * Le destinataire doit etre exactement `buyer_id` ou `seller_id` de LA
 * MEME transaction, dans la MEME Organization — jamais presume depuis la
 * seule charge utile. Un refus est journalise SANS email ni identifiant
 * de destinataire.
 *
 * ## Preferences et journalisation — PRESERVEES TELLES QUELLES
 *
 * `NewMessageReceived` ne porte aujourd'hui AUCUNE preference de
 * destinataire (verifie : `via()` rend toujours `['mail']`, aucune garde
 * dans la classe) — ce job ne l'ajoute pas, il deplace l'envoi existant
 * SANS en changer la condition. La journalisation `email_logs` reste
 * AUTOMATIQUE via l'ecouteur global `NotificationSent`
 * (`AppServiceProvider`, deja verifie pour `TransactionStatusChanged`) :
 * aucune ecriture propre a ce job.
 *
 * ## `$tries = 1` — meme doctrine que toute la famille
 *
 * Aucune reprise automatique : `NewMessageReceived` n'a pas de garde
 * d'idempotence propre, la rejouer aveuglement risquerait un second
 * envoi sur une panne transitoire du transport.
 *
 * ## Integrite de la messagerie si la mise en file echoue
 *
 * Le message est DEJA ecrit et commis avant que ce job ne soit meme
 * construit (`MessageThread::sendMessage()` dispatche depuis
 * `DB::afterCommit()`, APRES la transaction qui cree le message). Un
 * echec de mise en file (verifie par l'appelant, sous `rescue()`) ne
 * peut donc jamais faire disparaitre un message deja envoye dans la
 * conversation.
 */
class SendNewMessageReceivedEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-new-message-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $transactionId,
        public readonly string $messageId,
        public readonly string $recipientId,
    ) {
        $this->onQueue(self::DEDICATED_QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(): void
    {
        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->find($this->transactionId);

        if ($transaction === null) {
            Log::warning('SendNewMessageReceivedEmail: transaction introuvable au moment du traitement.', [
                'transaction_id' => $this->transactionId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $this->withOrganizationContext($transaction->organization_id, function (?Organization $organization) use ($transaction): void {
            if ($organization === null) {
                Log::warning('SendNewMessageReceivedEmail: Organization introuvable, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            $message = Message::find($this->messageId);

            if ($message === null) {
                Log::warning('SendNewMessageReceivedEmail: message introuvable au moment du traitement.', [
                    'transaction_id' => $this->transactionId,
                    'message_id' => $this->messageId,
                ]);

                return;
            }

            $recipient = User::find($this->recipientId);

            if ($recipient === null) {
                Log::warning('SendNewMessageReceivedEmail: destinataire introuvable, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'recipient_id' => $this->recipientId,
                ]);

                return;
            }

            // Isolation explicite (meme arbitrage que SendTransactionStatusChangedEmail,
            // PR #862) : seuls l'acheteur et le vendeur DE CETTE transaction,
            // DANS la MEME Organization, sont des destinataires legitimes.
            $estDestinataireLegitime = in_array($this->recipientId, [$transaction->buyer_id, $transaction->seller_id], true)
                && $recipient->organization_id === $transaction->organization_id;

            if (! $estDestinataireLegitime) {
                Log::warning('SendNewMessageReceivedEmail: destinataire illegitime, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            $recipient->notify(new NewMessageReceived($transaction, $message));
        });
    }

    /**
     * Idiome repris A L'IDENTIQUE de `SendTransactionStatusChangedEmail` /
     * `SendReviewReceivedEmail` (TASK-1854/1855) — voir le docblock de
     * classe.
     *
     * @template TReturn
     *
     * @param  callable(?Organization): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationContext(string $organizationId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('current_organization');
        $previous = $hadPrevious ? app('current_organization') : null;
        $organization = Organization::query()->whereKey($organizationId)->first();

        if ($organization) {
            app()->instance('current_organization', $organization);
        } elseif ($hadPrevious) {
            app()->forgetInstance('current_organization');
        }

        try {
            return $callback($organization);
        } finally {
            if ($hadPrevious) {
                app()->instance('current_organization', $previous);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }
}
