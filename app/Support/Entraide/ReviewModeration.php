<?php

namespace App\Support\Entraide;

use App\Models\Review;
use App\Models\ReviewModerationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1808 §2 — le point d'ecriture UNIQUE de toute moderation d'un avis
 * depuis le cockpit SuperAdmin. Meme esprit qu'`EvaluationEntraide` pour le
 * depot membre : UNE classe, UN endroit qui sait ecrire, pour que le journal
 * ne puisse jamais diverger du contenu reellement modifie.
 *
 * ## Les quatre proprietes tenues par chaque methode
 *
 * 1. l'ORIGINAL est capture AVANT toute ecriture — jamais relu APRES, ce qui
 *    rendrait le journal menteur en cas de modifications successives ;
 * 2. chaque geste est enferme dans sa PROPRE `DB::transaction()` (point de
 *    sauvegarde) — meme raison que partout ailleurs dans ce depot : sous
 *    PostgreSQL, une requete en echec avorte la transaction en cours et
 *    empoisonne la connexion (T1806 §16a-bis, T1808 §6) ;
 * 3. la moyenne publique de la personne evaluee est RECALCULEE des que la
 *    visibilite ou la note changent — jamais laissee perimee ;
 * 4. une ligne de journal par intervention, jamais reecrite.
 */
final class ReviewModeration
{
    /**
     * Modifie la note et/ou le mot. Le CDC ne demande pas de borner les
     * valeurs autrement que ce que `reviews.rating` exige deja (NOT NULL,
     * 1-5) : un rating `null` ici laisse la note EN L'ETAT, un comment
     * `null` efface le mot (reprend la meme convention que la saisie membre,
     * ou une chaine vide publiee equivaut a « pas de mot »).
     */
    public static function modifier(Review $avis, User $acteur, ?int $note, ?string $mot, ?string $motif): void
    {
        DB::transaction(function () use ($avis, $acteur, $note, $mot, $motif) {
            self::journaliser($avis, $acteur, ReviewModerationLog::ACTION_UPDATED, $motif);

            $avis->update([
                'rating' => $note ?? $avis->rating,
                'comment' => $mot !== null ? (trim($mot) !== '' ? trim($mot) : null) : $avis->comment,
                'moderated_by' => $acteur->id,
                'moderation_reason' => $motif,
            ]);
        });

        $avis->reviewed?->recalculateRating();
    }

    public static function masquer(Review $avis, User $acteur, ?string $motif): void
    {
        if ($avis->hidden_at !== null) {
            return;
        }

        DB::transaction(function () use ($avis, $acteur, $motif) {
            self::journaliser($avis, $acteur, ReviewModerationLog::ACTION_HIDDEN, $motif);

            $avis->update([
                'hidden_at' => now(),
                'moderated_by' => $acteur->id,
                'moderation_reason' => $motif,
            ]);
        });

        $avis->reviewed?->recalculateRating();
    }

    public static function demasquer(Review $avis, User $acteur, ?string $motif): void
    {
        if ($avis->hidden_at === null) {
            return;
        }

        DB::transaction(function () use ($avis, $acteur, $motif) {
            self::journaliser($avis, $acteur, ReviewModerationLog::ACTION_UNHIDDEN, $motif);

            $avis->update([
                'hidden_at' => null,
                'moderated_by' => $acteur->id,
                'moderation_reason' => $motif,
            ]);
        });

        $avis->reviewed?->recalculateRating();
    }

    /**
     * SUPPRIME, mais SoftDeletes : jamais un DELETE SQL definitif depuis ce
     * cockpit, pour tenir « conserver l'original » (CDC §2.D). L'unique
     * emplacement (`unique(transaction_id)`) reste pris — voir le docbloc de
     * `Transaction::hasReviewFrom()` — un second avis sur la meme entraide
     * reste donc impossible meme apres suppression.
     */
    public static function supprimer(Review $avis, User $acteur, ?string $motif): void
    {
        DB::transaction(function () use ($avis, $acteur, $motif) {
            self::journaliser($avis, $acteur, ReviewModerationLog::ACTION_DELETED, $motif);

            $avis->update(['moderated_by' => $acteur->id, 'moderation_reason' => $motif]);
            $avis->delete();
        });

        $avis->reviewed?->recalculateRating();
    }

    public static function restaurer(Review $avis, User $acteur, ?string $motif): void
    {
        DB::transaction(function () use ($avis, $acteur, $motif) {
            self::journaliser($avis, $acteur, ReviewModerationLog::ACTION_RESTORED, $motif);

            $avis->restore();
            $avis->update(['moderated_by' => $acteur->id, 'moderation_reason' => $motif]);
        });

        $avis->reviewed?->recalculateRating();
    }

    /**
     * L'ORIGINAL est lu sur `$avis` AVANT toute mutation de cette classe —
     * chaque methode publique appelle ceci en PREMIER, a l'interieur du
     * meme point de sauvegarde que l'ecriture qu'elle journalise.
     */
    private static function journaliser(Review $avis, User $acteur, string $action, ?string $motif): void
    {
        ReviewModerationLog::create([
            'review_id' => $avis->id,
            'organization_id' => $avis->organization_id,
            'actor_id' => $acteur->id,
            'action' => $action,
            'original_rating' => $avis->rating,
            'original_comment' => $avis->comment,
            'reason' => $motif,
        ]);
    }
}
