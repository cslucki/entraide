<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
use App\Services\Entraide\EvaluationInviteMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1855 — livrer l'invitation a evaluer (`EvaluationInviteMailer`)
 * hors du thread HTTP de `TransactionController::complete()`.
 *
 * ## Sequence PRESERVEE A L'IDENTIQUE — regle produit, pas touchee
 *
 * `handle()` reprend EXACTEMENT la sequence qui vivait avant ce correctif
 * dans `complete()` : verifier `evaluation_invited_at IS NULL`, appeler
 * le mailer, marquer SEULEMENT si l'envoi a reussi (`envoyer()` rend
 * `true` seulement si le transport a ACCEPTE le message — jamais sur un
 * simple rendu). Rien de resserre, rien d'assoupli.
 *
 * ## Arbitrage MASTER (PR #862, revue) — ne jamais inviter si un avis existe deja
 *
 * `EvaluationInviteMailer::envoyer()` n'a jamais verifie qu'un avis existe
 * deja avant d'inviter a en deposer un — vrai AVANT ce correctif, et le
 * demeure (ce mailer n'est pas touche). La garde vit donc ICI, dans le
 * job, via `Transaction::hasReviewFrom($buyer_id)` — la MEME regle
 * metier deja utilisee par `evaluationEnAttente()`/`evaluationDeposeeOuIgnoree()` :
 * `withTrashed()` compte aussi un avis MODERE (masque) ou SUPPRIME, qui
 * occupe toujours son unique emplacement (`unique(transaction_id)`) —
 * un avis supprime par moderation n'est pas « jamais depose ». Sur ce
 * cas, `evaluation_invited_at` reste NULL (aucun envoi == aucun succes
 * SMTP a marquer, meme regle que partout ailleurs dans ce job) : rien ne
 * relance plus tard, ce job ne s'execute qu'une fois, a la cloture.
 * **Signale, non corrige** : cette sequence n'est PAS atomique au sens
 * strict — un check `exists()` suivi d'un `envoyer()` puis d'un `update()`
 * laisse une fenetre theorique si DEUX jobs s'executaient en vraie
 * concurrence pour la MEME entraide (improbable : la Managed Queue
 * `notifications` est `minReplicas:0, maxReplicas:1`, confirme TASK-1854,
 * et `complete()` ne peut cloturer la MEME transaction deux fois — verifie
 * par le controle de statut `in_array($tx->status, ['accepted','buyer_done'])`
 * AVANT toute ecriture). Le docblock de `EvaluationInviteMailer` assume
 * deja EXPLICITEMENT ce risque pour une raison documentee (« le risque
 * rare d'un double envoi... pese moins que celui de ne jamais relancer un
 * email reellement jamais parti ») — ce correctif ne fait que deplacer
 * cette sequence D'UN THREAD A UN AUTRE, sans en changer la nature.
 *
 * ## Autorisation TENANT — meme idiome que `SendTransactionStatusChangedEmail`
 *
 * Voir son docblock : lecture initiale hors scope (`withoutGlobalScope`),
 * puis liaison/restauration via `withOrganizationContext()`.
 *
 * ## `$tries = 1`
 *
 * Aucune reprise automatique, pour la MEME raison que documentee dans
 * `EvaluationInviteMailer` : rejouer sur un echec transitoire risquerait
 * un second envoi.
 */
class SendEvaluationInviteEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-evaluation-invite-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $transactionId)
    {
        $this->onQueue(self::DEDICATED_QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(EvaluationInviteMailer $mailer): void
    {
        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->find($this->transactionId);

        if ($transaction === null) {
            Log::warning('SendEvaluationInviteEmail: transaction introuvable au moment du traitement.', [
                'transaction_id' => $this->transactionId,
            ]);

            return;
        }

        $this->withOrganizationContext($transaction->organization_id, function (?Organization $organization) use ($transaction, $mailer): void {
            if ($organization === null) {
                Log::warning('SendEvaluationInviteEmail: Organization introuvable, invitation non envoyee.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            // Arbitrage MASTER (PR #862, correctif CI) — `hasReviewFrom()`
            // interroge `reviews`, une requete REELLE qui peut echouer (la
            // CI Feature/PostgreSQL l'a prouve : un test TASK-1806 supprime
            // delibrement cette table pour simuler une panne). Hors
            // `DB::transaction()`, un echec PostgreSQL AVORTE tout le bloc
            // de transaction ambiant (celui d'un test sous `RefreshDatabase`,
            // ou une transaction englobante en PROD) pour TOUTES les
            // requetes suivantes — meme motif, et meme remede par
            // SAVEPOINT, que `TransactionController::complete()` applique
            // deja autour de `EvaluationEntraide::enregistrer()`. Seules les
            // DEUX lectures sont protegees : l'envoi SMTP lui-meme reste
            // HORS transaction, pour ne jamais tenir la connexion ouverte
            // pendant un appel reseau.
            $aEnvoyer = DB::transaction(function () use ($transaction): bool {
                // Sequence IDENTIQUE a l'ancien appelant synchrone — voir le
                // docblock de classe.
                $invitationEligible = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
                    ->whereKey($transaction->id)
                    ->whereNull('evaluation_invited_at')
                    ->exists();

                if (! $invitationEligible) {
                    return false;
                }

                // Arbitrage MASTER (PR #862) — meme regle que `evaluationEnAttente()`
                // (`withTrashed()` : un avis modere ou supprime occupe deja
                // l'unique emplacement de cette transaction). Rien a marquer
                // puisqu'aucun envoi n'a lieu.
                if ($transaction->hasReviewFrom($transaction->buyer_id)) {
                    Log::info('SendEvaluationInviteEmail: un avis existe deja pour cette transaction, invitation non envoyee.', [
                        'transaction_id' => $this->transactionId,
                    ]);

                    return false;
                }

                return true;
            });

            if (! $aEnvoyer) {
                return;
            }

            if ($mailer->envoyer($transaction)) {
                Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
                    ->whereKey($transaction->id)
                    ->whereNull('evaluation_invited_at')
                    ->update(['evaluation_invited_at' => now()]);
            }
        });
    }

    /**
     * Idiome repris A L'IDENTIQUE de `SendTransactionStatusChangedEmail` /
     * `DossierFileIndexer::withOrganizationContext()` — voir le docblock
     * de classe.
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
