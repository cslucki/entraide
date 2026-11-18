<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TransactionStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1855 — livrer `TransactionStatusChanged` hors du thread HTTP, pour
 * l'unique declencheur `TransactionController::complete()` (cloture d'une
 * entraide).
 *
 * ## Perimetre VOLONTAIREMENT etroit
 *
 * `TransactionStatusChanged` est PARTAGEE par `approve()`, `refuse()`,
 * `complete()` et `confirm()` (audit TASK-1856, verifie ligne a ligne
 * dans `TransactionController.php`). Mettre `ShouldQueue` sur la classe
 * de notification elle-meme aurait change les QUATRE declencheurs d'un
 * seul coup — exactement la « modification globale » que le mandat
 * interdit. Ce job ne deporte donc QUE les deux appels de `complete()`
 * (acheteur, vendeur) ; `approve()`/`refuse()`/`confirm()` restent
 * INCHANGES, synchrones, hors de ce correctif. Si l'un d'eux est migre un
 * jour, ce job est deja assez generique (transactionId + recipientId)
 * pour etre reutilise SANS modification — mais cette migration n'est PAS
 * demandee ici.
 *
 * ## La charge utile est un IDENTIFIANT par destinataire, rien d'autre
 *
 * Un job par destinataire (deux dispatches depuis `complete()`, un par
 * acheteur/vendeur) — meme motif que `NotificationEmitter::emit()` pour
 * chaque destinataire d'une Boucle. Le worker relit la transaction FRAICHE
 * (son statut a pu changer depuis la mise en file — improbable pour une
 * cloture deja actee, mais jamais presume) et le destinataire par son
 * identifiant.
 *
 * ## Autorisation TENANT — lue PUIS restauree, meme idiome que TASK-1854
 *
 * `Transaction` porte `BelongsToOrganizationScope` (contrairement a
 * `Review`) : la PREMIERE lecture doit donc explicitement l'ecarter
 * (`withoutGlobalScope`, meme motif que `routes/channels.php` pour le
 * canal `conversation.{transactionId}`) avant de pouvoir lire son
 * `organization_id` et lier le tenant. `withOrganizationContext()`
 * reprend ensuite A L'IDENTIQUE l'idiome deja en place
 * (`AiTurnExecutor::dansLeTenant()`, `DossierFileIndexer::
 * withOrganizationContext()`, et le meme code deja introduit par
 * TASK-1854) : etat precedent sauvegarde, restaure dans un `finally`
 * meme si la notification leve.
 *
 * ## `$tries = 1` — meme doctrine que les jobs de notification existants
 *
 * Aucune reprise automatique : un echec est journalise, jamais rejoue —
 * `TransactionStatusChanged` n'a pas de garde `email_logs`/idempotence
 * propre (contrairement a `ReviewReceivedMailer`), donc la rejouer
 * aveuglement risquerait un second envoi sur une panne simplement
 * transitoire du transport.
 */
class SendTransactionStatusChangedEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-transaction-status-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $transactionId,
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
            Log::warning('SendTransactionStatusChangedEmail: transaction introuvable au moment du traitement.', [
                'transaction_id' => $this->transactionId,
                'recipient_id' => $this->recipientId,
            ]);

            return;
        }

        $this->withOrganizationContext($transaction->organization_id, function (?Organization $organization) use ($transaction): void {
            if ($organization === null) {
                Log::warning('SendTransactionStatusChangedEmail: Organization introuvable, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            $recipient = User::find($this->recipientId);

            if ($recipient === null) {
                Log::warning('SendTransactionStatusChangedEmail: destinataire introuvable, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'recipient_id' => $this->recipientId,
                ]);

                return;
            }

            $recipient->notify(new TransactionStatusChanged($transaction));
        });
    }

    /**
     * Idiome repris A L'IDENTIQUE de `DossierFileIndexer::
     * withOrganizationContext()` / `SendReviewReceivedEmail` (TASK-1854) —
     * voir le docblock de classe.
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
