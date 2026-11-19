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
 * le declencheur `TransactionController::complete()` (cloture d'une
 * entraide). TASK-1857 — meme job, reutilise SANS modification de son
 * mecanisme d'envoi pour `approve()` et `refuse()` (audit TASK-1856 avait
 * deja identifie la trace SMTP lente du 15:18:22 UTC comme provenant
 * d'`approve()`, synchrone).
 *
 * ## Perimetre
 *
 * `TransactionStatusChanged` est PARTAGEE par `approve()`, `refuse()`,
 * `complete()` et `confirm()` (audit TASK-1856, verifie ligne a ligne
 * dans `TransactionController.php`). Mettre `ShouldQueue` sur la classe
 * de notification elle-meme aurait change les QUATRE declencheurs d'un
 * seul coup — exactement la « modification globale » que les deux
 * mandats ont exclue. Ce job deporte desormais `approve()`, `refuse()`
 * ET `complete()` (un par destinataire a chaque fois) ; `confirm()` reste
 * INCHANGE, synchrone, hors de ce correctif — chemin herite que plus
 * aucun code ne produit (zero ligne mesuree sur le jumeau au 11/11,
 * aucune anomalie demontree a son sujet, TASK-1857 audit).
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
 *
 * ## Arbitrage MASTER (PR #862, revue) — isolation explicite du destinataire
 *
 * La charge utile n'est qu'un `recipientId` scalaire : un worker qui
 * l'executerait bien plus tard, sur une transaction entre-temps modifiee
 * (ou un identifiant corrompu/rejoue), ne doit jamais notifier n'importe
 * qui. Apres relecture de la transaction et liaison du tenant, `handle()`
 * verifie donc EXPLICITEMENT, avant tout envoi : le destinataire
 * appartient a l'Organization de la transaction, ET son identifiant est
 * exactement `buyer_id` ou `seller_id` — les deux SEULS destinataires
 * legitimes de cette notification. Un refus est journalise SANS email ni
 * identifiant de destinataire (seulement l'identifiant de transaction et
 * d'Organization) : un refus repete serait le signe d'un identifiant
 * corrompu ou rejoue, pas une information a exposer en clair.
 *
 * ## Arbitrage MASTER (TASK-1857) — `expectedStatus`, contre le statut perime
 *
 * `approve()`/`refuse()` posent un statut TRANSITOIRE (`accepted`,
 * `refused`) — contrairement a `complete()`, dont le statut (`completed`)
 * est terminal et ne peut plus changer derriere lui. Un job mis en file
 * depuis `approve()` pourrait donc s'executer APRES que la transaction
 * ait deja ete clôturee (`complete()`), et enverrait alors un email
 * « votre proposition a ete acceptee » pour un echange deja termine —
 * trompeur, sans avoir jamais ete faux au moment ou il a ete ecrit.
 *
 * `$expectedStatus`, facultatif (`null` par defaut — comportement
 * INCHANGE pour `complete()`, qui ne le passe jamais : compatibilite
 * ascendante totale avec TASK-1855) : quand il est fourni, `handle()` le
 * compare au statut COURANT, relu au moment de l'execution, APRES la
 * verification du destinataire. Une divergence n'envoie rien et
 * journalise un code stable, sans email ni donnee personnelle — meme
 * doctrine que l'isolation du destinataire ci-dessus.
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
        public readonly ?string $expectedStatus = null,
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

            // Arbitrage MASTER (PR #862) — isolation explicite : seuls
            // l'acheteur et le vendeur DE CETTE transaction, DANS la MEME
            // Organization, sont des destinataires legitimes.
            $estDestinataireLegitime = in_array($this->recipientId, [$transaction->buyer_id, $transaction->seller_id], true)
                && $recipient->organization_id === $transaction->organization_id;

            if (! $estDestinataireLegitime) {
                Log::warning('SendTransactionStatusChangedEmail: destinataire illegitime, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            // Arbitrage MASTER (TASK-1857) — statut perime : un job mis en
            // file depuis un statut TRANSITOIRE (`approve()`/`refuse()`)
            // peut s'executer apres que la transaction ait change d'etat
            // (ex. deja cloturee). `$expectedStatus` est `null` pour
            // `complete()` (statut terminal, aucune divergence possible) —
            // cette garde ne change donc RIEN a son comportement existant.
            if ($this->expectedStatus !== null && $transaction->status !== $this->expectedStatus) {
                Log::warning('SendTransactionStatusChangedEmail: statut perime, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
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
