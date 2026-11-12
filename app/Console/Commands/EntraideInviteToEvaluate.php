<?php

namespace App\Console\Commands;

use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
use App\Services\Entraide\EvaluationInviteMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1808 §6 — l'invitation a evaluer, envoyee APRES la cloture, UNE SEULE
 * FOIS, et seulement si elle a encore un sens.
 *
 * ## Pourquoi une commande planifiee, et pas un envoi immediat a la cloture
 *
 * Deux contraintes d'infrastructure l'imposent (auditees en 1d-3 du
 * taskfile) : `QUEUE_CONNECTION=sync` en PROD ignore tout `->delay()`, et
 * l'allowlist de `queue:work` (`routes/console.php`) exclut deliberement la
 * queue `default` — y dispatcher cette invitation la ferait jamais
 * consommer, exactement le defaut silencieux que T1511 avait corrige. Le
 * planificateur, lui, fonctionne deja (`feed:publish-scheduled`,
 * `knowledge:derive-due`) : c'est la seule voie fiable.
 *
 * Le differe est aussi un choix produit : la sequence des deux micro-ecrans
 * se joue en general tout de suite, sur la meme visite que la cloture. Un
 * email envoye dans l'instant serait souvent deja perime a l'arrivee.
 *
 * ## Les conditions d'envoi, et pourquoi chacune
 *
 * - `status = completed`, pas une discussion directe : rien a evaluer sinon.
 * - `completed_at` plus ancien que `DELAI_MINUTES` : laisse le temps a la
 *   sequence immediate de se jouer avant de relancer par email.
 * - `evaluation_invited_at IS NULL` : « une invitation MAXIMUM », et c'est la
 *   garde qui le tient — relue a CHAQUE passage, jamais seulement a l'ecriture.
 * - `evaluationEnAttente()` (pas de renoncement explicite, pas d'avis deja
 *   depose) : « eviter un email devenu inutile ». Un simple « Plus tard »
 *   (`evaluation_deferred_at`) n'empeche PAS l'invitation — differer dans le
 *   fil et recevoir une seule relance par email sont deux choses distinctes,
 *   et aucune des deux n'est une pression repetee puisque chacune ne se
 *   produit qu'une fois.
 */
class EntraideInviteToEvaluate extends Command
{
    protected $signature = 'entraide:invite-to-evaluate';

    protected $description = 'Send the one-time evaluation invitation email for mutual aids closed long enough ago';

    /**
     * Le temps laisse a la sequence immediate (etoiles puis mot) avant de
     * relancer par email. Meme ordre de grandeur que la fenetre d'inactivite
     * de `knowledge:derive-due` (dix minutes) — un rythme deja etabli dans ce
     * depot pour « assez de temps pour agir seul, pas assez pour oublier ».
     */
    public const DELAI_MINUTES = 10;

    public function handle(EvaluationInviteMailer $mailer): int
    {
        // TASK-1808, meme defaut deja documente dans `EntraideCommentMailer` :
        // ce code tourne dans un BALAYAGE PLANIFIE, sans `current_organization`
        // en contexte. Le scope de tenant de `Transaction` echoue alors FERME
        // et ne rendrait AUCUNE ligne, de QUELQUE Organization que ce soit —
        // silencieusement. Un balayage global doit precisement couvrir TOUTES
        // les Organizations : retirer le scope ici n'affaiblit aucune
        // frontiere, il n'y a pas de frontiere a tenir pour une tache qui vise
        // le systeme entier.
        $eligibles = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('status', 'completed')
            ->whereNull('evaluation_invited_at')
            ->whereNull('reviews_skipped_at')
            ->where('completed_at', '<=', now()->subMinutes(self::DELAI_MINUTES))
            // `unique(transaction_id)` (T1806) garantit au plus UNE ligne, et
            // la policy garantit qu'elle est toujours celle de l'aide :
            // « aucun avis depose » se lit donc par la seule existence.
            ->whereDoesntHave('reviews')
            ->get()
            ->reject(fn (Transaction $t) => $t->isDirectConversation());

        if ($eligibles->isEmpty()) {
            $this->info('No mutual aid due for an evaluation invitation.');

            return Command::SUCCESS;
        }

        $envoyees = 0;

        foreach ($eligibles as $transaction) {
            // Point de sauvegarde dedie — meme raison que dans
            // `TransactionController::complete()` (T1806, §16a-bis) : sous
            // PostgreSQL une requete en echec AVORTE la transaction en cours
            // et empoisonne la connexion. Un envoi qui leve ne doit pas faire
            // tomber le passage des entraides suivantes de ce meme balayage.
            try {
                $envoye = DB::transaction(function () use ($mailer, $transaction) {
                    $reussi = $mailer->envoyer($transaction);

                    if ($reussi) {
                        // `whereNull` : si un autre processus a deja marque
                        // cette meme entraide entre notre lecture et cet
                        // instant, 0 ligne est touchee et rien n'est perdu —
                        // seulement une invitation envoyee deux fois dans le
                        // pire cas d'une course, jamais zero.
                        // Meme raison que la requete d'eligibilite plus haut :
                        // une requete RECONSTRUITE depuis le modele porte a
                        // nouveau le scope de tenant, qui echouerait FERME
                        // ici aussi et ne toucherait silencieusement aucune
                        // ligne.
                        Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
                            ->whereKey($transaction->id)
                            ->whereNull('evaluation_invited_at')
                            ->update(['evaluation_invited_at' => now()]);
                    }

                    return $reussi;
                });
            } catch (\Throwable $e) {
                report($e);
                $envoye = false;
            }

            if ($envoye) {
                $envoyees++;
            }
        }

        $this->info("Sent {$envoyees} evaluation invitation(s) out of {$eligibles->count()} eligible.");

        return Command::SUCCESS;
    }
}
