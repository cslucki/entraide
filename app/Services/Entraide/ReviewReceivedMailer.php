<?php

namespace App\Services\Entraide;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Support\Notifications\GabaritEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1809 §4 — l'email a l'aidant quand son evaluation arrive.
 *
 * ## Pourquoi un envoi direct, et pas le catalogue
 *
 * Meme raisonnement que `EvaluationInviteMailer` (T1808 §6) : un geste
 * ponctuel, UNE fois par avis, jamais un canal reglable dans le Centre de
 * notifications — rien ne le demande. Meme patron : `Mail::html()` direct,
 * journalise dans `email_logs`, rendu par `GabaritEmail::rendre()` depuis un
 * gabarit `system_email_templates` Global, administrable, FR/EN.
 *
 * ## Le declencheur, et ce qu'il garantit
 *
 * Appele UNIQUEMENT par `EvaluationEntraide::enregistrer()`, juste apres la
 * creation de la ligne `Review` — jamais par `ReviewModeration`
 * (modifier/masquer/demasquer/supprimer/restaurer), qui ne cree jamais de
 * ligne et ne passe jamais par `enregistrer()`. « Jean ne doit pas avoir
 * besoin de revenir sur BouclePro pour connaitre sa note et lire le
 * commentaire » : le gabarit porte donc la note (etoiles ET x/5) et le
 * commentaire s'il existe, en texte TOUJOURS echappe (`GabaritEmail`/
 * `EmailerService::interpolate()` applique `e()` a chaque variable — jamais
 * de HTML non echappe issu d'un commentaire membre).
 *
 * Idempotent par construction : `enregistrer()` ne peut creer qu'UNE ligne
 * `Review` par entraide (`unique(transaction_id)`, garde `withTrashed()`),
 * donc cette methode n'est jamais appelee deux fois pour le meme avis.
 */
final class ReviewReceivedMailer
{
    public const SLUG = 'entraide_review_received';

    /**
     * Envoie l'email et journalise le resultat.
     *
     * Jamais bloquant : appelee sous `rescue()` par l'appelant, et ici meme
     * toute exception de transport est capturee, journalisee `failed`, et ne
     * remonte jamais — un email qui echoue ne doit jamais faire echouer le
     * depot de l'avis.
     */
    public function envoyer(Review $avis): bool
    {
        $avis->loadMissing(['transaction', 'reviewer', 'reviewed']);

        $aidant = $avis->reviewed;
        $aide = $avis->reviewer;
        $transaction = $avis->transaction;

        if ($aidant === null || $aide === null || $aidant->email === null) {
            Log::warning('entraide_review_received could not be sent: missing recipient data', [
                'review_id' => $avis->id,
                'transaction_id' => $avis->transaction_id,
                'organization_id' => $avis->organization_id,
                'has_reviewed' => $aidant !== null,
                'has_reviewer' => $aide !== null,
                'has_email' => $aidant?->email !== null,
            ]);

            return false;
        }

        $organization = Organization::query()->whereKey($avis->organization_id)->first();

        $locale = $aidant->preferred_locale ?: 'fr';
        $langueOrigine = app()->getLocale();
        app()->setLocale($locale);

        try {
            $url = $transaction !== null
                ? (Route::has('organization.messages.show')
                    ? route('organization.messages.show', [
                        'organization' => $organization?->slug,
                        'transaction' => $transaction->id,
                    ])
                    : route('messages.show', ['transaction' => $transaction->id]))
                : null;

            // BUG CONFIRME (recette Cyril, 12/11, screenshot a l'appui) :
            // `$aide->first_name` seul peut etre NULL (comptes de demo, comptes
            // crees avant la separation prenom/nom) — un `isset()` sur une
            // variable `null` dans `EmailerService::interpolate()` echoue, et
            // le jeton `{{ aide_first_name }}` ressort LITTERALEMENT dans
            // l'email reel, sujet compris. Meme repli a trois etages que
            // `$_bentoPrenomAidant` dans `message-thread.blade.php` : prenom,
            // puis nom complet, jamais une chaine vide ou nulle.
            $aidePrenom = $aide->isDeactivated()
                ? __('profile.deactivated_user')
                : ($aide->first_name ?: ($aide->name ?: __('profile.deactivated_user')));
            $note = (int) $avis->rating;

            // Les etoiles ET le chiffre : « Jean ne doit pas avoir besoin de
            // revenir sur BouclePro pour connaitre sa note ». Pur texte —
            // aucune balise, pour ne jamais rien casser sous `e()`.
            $etoiles = str_repeat('★', $note).str_repeat('☆', 5 - $note);

            // Le commentaire est un texte UNIQUEMENT — jamais de balise
            // construite ici, puisque `interpolate()` echappe tout ce qui
            // est substitue : une balise qu'on poserait soi-meme dans cette
            // variable sortirait donc en texte litteral. Un commentaire
            // absent laisse un paragraphe vide dans le gabarit — artefact
            // mineur, documente, prefere a du HTML conditionnel que ce
            // moteur d'interpolation ne sait pas produire.
            $commentaire = trim((string) ($avis->comment ?? ''));

            $rendu = app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $avis->organization_id,
                langue: $locale,
                sender: null,
                vars: [
                    'recipient_name' => $aidant->publicDisplayName(),
                    'recipient_email' => $aidant->email,
                    'aide_name' => $aide->publicDisplayName(),
                    'aide_first_name' => $aidePrenom,
                    'rating_stars' => $etoiles,
                    'rating_fraction' => "{$note}/5",
                    'comment_text' => $commentaire,
                    'conversation_url' => $url ?? '',
                    'organization_name' => $organization?->name ?? '',
                    'app_name' => config('app.name'),
                ],
                repli: fn () => [
                    'subject' => (string) __('notifications.email.entraide_review_received.subject', ['aide' => $aidePrenom]),
                    'html' => str_replace(
                        [':aide', ':etoiles', ':note', ':commentaire'],
                        [e($aidePrenom), $etoiles, "{$note}/5", e($commentaire)],
                        (string) __('notifications.email.entraide_review_received.body'),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }

        $logData = [
            'source' => 'entraide-review-received',
            'review_id' => $avis->id,
            'transaction_id' => $avis->transaction_id,
            'organization_id' => $avis->organization_id,
            'template_used' => $rendu['fallback_reason'] === null ? 'system_email_template' : 'blade_fallback',
            'fallback_reason' => $rendu['fallback_reason'],
        ];

        try {
            Mail::html($rendu['body'], function ($message) use ($aidant, $rendu) {
                $message->to($aidant->email)->subject($rendu['subject']);
            });

            EmailLog::create([
                'user_id' => $aidant->id,
                'organization_id' => $avis->organization_id,
                'to_email' => $aidant->email,
                'subject' => $rendu['subject'],
                'status' => EmailLog::STATUS_SENT,
                'data' => $logData,
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            EmailLog::create([
                'user_id' => $aidant->id,
                'organization_id' => $avis->organization_id,
                'to_email' => $aidant->email,
                'subject' => $rendu['subject'],
                'status' => EmailLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'data' => $logData,
            ]);

            return false;
        }
    }
}
