<?php

namespace App\Support\Entraide;

use App\Models\Review;
use App\Models\Transaction;
use App\Services\Entraide\ReviewReceivedMailer;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;

/**
 * TASK-1806 (arbitrage du 11/11, parcours a TROIS etapes) — l'evaluation
 * s'enregistre depuis DEUX chemins, et un seul code la pose.
 *
 * ## Les deux chemins, et pourquoi ils coexistent
 *
 * 1. **Le parcours courant** : l'aide confirme avoir recu l'aide ET laisse (ou
 *    non) sa note dans le MEME geste — `TransactionController::complete()`.
 *    C'est la 3e et derniere etape visible : il n'y a pas d'etape « evaluation »
 *    separee, et c'est tout le propos de l'arbitrage.
 *
 * 2. **Les entraides deja terminees AVANT cet arbitrage** : elles sont
 *    `completed` sans avis et sans renoncement (7 lignes mesurees sur le jumeau
 *    au 11/11). Elles gardent la carte d'evaluation du Bento, servie par
 *    `ReviewController` — sinon elles resteraient a jamais sans avis, et le
 *    rattrapage prevu en T1807 n'aurait plus de porte ou frapper.
 *
 * Ce n'est donc PAS une 4e etape cachee : pour toute entraide nee apres cet
 * arbitrage, l'evaluation se decide a l'etape 3 et nulle part ailleurs.
 *
 * ## Pas de note = renoncement EXPLICITE
 *
 * `reviews.rating` est NOT NULL : « ne pas evaluer » ne peut pas s'ecrire comme
 * un avis vide, et inventer une note fausserait la moyenne du profil de
 * l'aidant. On ecrit donc `reviews_skipped_at`, qui distingue « a choisi de ne
 * pas evaluer » de « pas encore evalue » — la difference que T1807 devra lire
 * pour ne relancer personne a tort.
 */
final class EvaluationEntraide
{
    /**
     * Enregistre l'evaluation de l'aidant par l'aide, ou son renoncement.
     *
     * Idempotente par construction : un avis deja depose n'est jamais double,
     * ce que la contrainte `unique(transaction_id)` garantit de toute facon au
     * niveau de la base. La garde ici evite seulement de lui faire lever une
     * exception sur un double envoi.
     *
     * @param  int|null  $note  1 a 5, ou `null` pour « sans avis »
     */
    public static function enregistrer(Transaction $entraide, ?int $note, ?string $mot = null): void
    {
        // TASK-1808 §2 (cockpit) — `withTrashed()` : un avis supprime par
        // moderation garde son emplacement UNIQUE (`unique(transaction_id)`),
        // voir le docbloc de `Transaction::hasReviewFrom()`.
        if ($entraide->reviews()->withTrashed()->exists()) {
            return;
        }

        if ($note === null) {
            $entraide->update(['reviews_skipped_at' => now()]);

            return;
        }

        $avis = Review::create([
            'transaction_id' => $entraide->id,
            'organization_id' => $entraide->organization_id,
            // Jamais « l'autre participant » : l'aide evalue, l'aidant est
            // evalue, dans les deux sens du parcours (demande ou proposition).
            'reviewer_id' => $entraide->buyer_id,
            'reviewed_id' => $entraide->seller_id,
            'rating' => $note,
            'comment' => $mot,
        ]);

        $entraide->seller?->recalculateRating();

        // TASK-1809 §4 — l'aidant est informe DANS L'EMAIL, sans avoir a
        // revenir sur BouclePro. Hooke ici et nulle part ailleurs : c'est le
        // SEUL point ou une ligne `Review` est reellement creee (la garde
        // `withTrashed()->exists()` plus haut rend cette ligne inatteignable
        // une seconde fois pour la meme entraide) — `ReviewModeration` ne
        // passe jamais par ici, donc une modification/un masquage SuperAdmin
        // ne declenche jamais cet envoi. `rescue()` : un echec d'envoi ne
        // doit jamais faire echouer le depot de l'avis lui-meme.
        rescue(
            fn () => app(ReviewReceivedMailer::class)->envoyer($avis),
            fn (\Throwable $e) => report($e),
            false,
        );

        // TASK-1809 (correctif Cyril, 12/11) — le partage dans le Flux
        // n'est plus un geste distinct, demande en plus de l'evaluation : il
        // est desormais AUTOMATIQUE, au MEME instant que l'avis. Meme
        // acteur que l'ancien geste explicite (l'AIDANT, jamais l'aide) et
        // meme emetteur idempotent — une discussion directe n'a toujours
        // rien a publier.
        if (! $entraide->isDirectConversation() && $entraide->seller !== null) {
            rescue(
                fn () => app(FeedEventEmitter::class)->emettre(
                    FeedEventCatalogue::ENTRAIDE_TERMINEE,
                    $entraide,
                    $entraide->seller,
                    $entraide->organization_id,
                ),
                fn (\Throwable $e) => report($e),
                false,
            );
        }
    }
}
