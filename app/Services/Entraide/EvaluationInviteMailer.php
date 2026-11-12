<?php

namespace App\Services\Entraide;

use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Transaction;
use App\Support\Notifications\GabaritEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1808 §6 — l'email d'invitation a evaluer l'aidant.
 *
 * ## Pourquoi ce fichier, et pas la machinerie du catalogue
 *
 * Ce gabarit n'est PAS une notification routee : aucun canal in-app, aucun
 * push, aucune preference a desactiver. C'est un geste ponctuel — UNE
 * invitation, jamais plus — declenche par une commande planifiee, pas par un
 * evenement de domaine. Le brancher sur `NotificationEmitter`/`MemberNotification`
 * ferait apparaitre une ligne dans le Centre de notifications de l'aide, ce
 * qu'aucune demande n'a jamais evoque et que §6 ne mentionne pas.
 *
 * Le patron repris est donc celui d'`OrganizationInvitationMailer` — un envoi
 * `Mail::html()` direct, journalise dans `email_logs` — mais le RENDU passe
 * par `GabaritEmail::rendre()` comme tous les gabarits plus recents
 * (`EntraideCommentMailer`, `TransactionProposalMailer`) : « pas un nouveau
 * systeme d'envoi email », le texte vient du meme catalogue administrable,
 * la meme resolution tenant+langue, la meme interpolation.
 *
 * ## Le destinataire est UNIQUE, et c'est une regle, pas un detail
 *
 * « Ne jamais envoyer a l'aidant un message indiquant qu'une evaluation lui a
 * ete demandee ou qu'elle n'a pas ete donnee. » Cette classe ne lit donc
 * jamais `$transaction->seller` comme destinataire — seulement comme variable
 * de texte (son prenom, dans le corps adresse a l'aide).
 *
 * ## L'idempotence ne vit PAS ici
 *
 * `transactions.evaluation_invited_at` est pose par l'appelant
 * (`EntraideInviteToEvaluateCommand`) sous une garde `whereNull`, pas par ce
 * mailer — exactement comme `seller_celebrated_at` est pose par
 * `MessageThread::celebrerSiCEstDu()` et non par le composant qui affiche les
 * coeurs. Un mailer qui ecrirait lui-meme son propre marqueur de succes ne
 * saurait pas distinguer un succes d'un echec AVANT d'avoir tente l'envoi.
 */
final class EvaluationInviteMailer
{
    public const SLUG = 'entraide_evaluation_invite';

    /**
     * Envoie l'invitation et journalise le resultat.
     *
     * Rend `true` seulement quand le transport a ACCEPTE le message —
     * jamais sur un simple rendu reussi. L'appelant decide, a partir de ce
     * booleen, s'il doit marquer l'entraide comme invitee ou retenter au
     * prochain passage.
     *
     * TASK-1808 — une exception pendant l'envoi est journalisee `failed` et
     * REND `false` : contrairement a la frontiere plus stricte de
     * `NotificationEmailDeliverer` (qui distingue `ambiguous` pour ne JAMAIS
     * rejouer un evenement recurrent et critique), cette invitation est un
     * geste de courtoisie, UNIQUE et a faible consequence — le risque rare
     * d'un double envoi sur une panne transitoire pese moins que celui de ne
     * jamais relancer un email reellement jamais parti. Meme choix
     * qu'`OrganizationInvitationMailer`, deliberement plus simple ici.
     */
    public function envoyer(Transaction $transaction): bool
    {
        $aide = $transaction->buyer;
        $aidant = $transaction->seller;

        if ($aide === null || $aidant === null || $aide->email === null) {
            Log::warning('entraide_evaluation_invite could not be sent: missing recipient data', [
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'has_buyer' => $aide !== null,
                'has_seller' => $aidant !== null,
                'has_email' => $aide?->email !== null,
            ]);

            return false;
        }

        $organization = Organization::query()->whereKey($transaction->organization_id)->first();

        $locale = $aide->preferred_locale ?: 'fr';
        $langueOrigine = app()->getLocale();
        app()->setLocale($locale);

        try {
            $url = Route::has('organization.messages.show')
                ? route('organization.messages.show', [
                    'organization' => $organization?->slug,
                    'transaction' => $transaction->id,
                ])
                : route('messages.show', ['transaction' => $transaction->id]);

            $aidantNom = $aidant->publicDisplayName();

            // Demande Cyril (12/11) — le gabarit nomme l'aidant par son
            // PRENOM dans la phrase d'invitation, pas par un pronom : meme
            // garde de confidentialite que `publicDisplayName()` (un compte
            // desactive ne revele jamais son prenom reel).
            $aidantPrenom = $aidant->isDeactivated() ? __('profile.deactivated_user') : $aidant->first_name;

            $rendu = app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $transaction->organization_id,
                langue: $locale,
                sender: null,
                vars: [
                    'recipient_name' => $aide->publicDisplayName(),
                    'recipient_email' => $aide->email,
                    'aidant_name' => $aidantNom,
                    'aidant_first_name' => $aidantPrenom,
                    'organization_name' => $organization?->name ?? '',
                    'evaluation_url' => $url,
                    'app_name' => config('app.name'),
                ],
                // Repli minimal : ce gabarit n'a pas de version Blade
                // dediee, a la difference des chemins plus anciens — le CDC
                // ne demande pas une troisieme version du meme texte a
                // tenir alignee pour une invitation facultative. Un rendu
                // en erreur log sa raison (dans `GabaritEmail`) et renvoie un
                // texte minimal mais complet, jamais une page blanche.
                repli: fn () => [
                    'subject' => (string) __('notifications.email.entraide_evaluation_invite.subject'),
                    'html' => str_replace(
                        [':aidant', ':url'],
                        [e($aidantNom), $url],
                        (string) __('notifications.email.entraide_evaluation_invite.body'),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }

        $logData = [
            'source' => 'entraide-evaluation-invite',
            'transaction_id' => $transaction->id,
            'organization_id' => $transaction->organization_id,
            'template_used' => $rendu['fallback_reason'] === null ? 'system_email_template' : 'blade_fallback',
            'fallback_reason' => $rendu['fallback_reason'],
        ];

        try {
            Mail::html($rendu['body'], function ($message) use ($aide, $rendu) {
                $message->to($aide->email)->subject($rendu['subject']);
            });

            EmailLog::create([
                'user_id' => $aide->id,
                'organization_id' => $transaction->organization_id,
                'to_email' => $aide->email,
                'subject' => $rendu['subject'],
                'status' => EmailLog::STATUS_SENT,
                'data' => $logData,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Echec journalise, jamais avale (meme exigence
            // qu'`OrganizationInvitationMailer`) : l'entraide reste
            // eligible, le prochain passage du planificateur retentera.
            report($e);

            EmailLog::create([
                'user_id' => $aide->id,
                'organization_id' => $transaction->organization_id,
                'to_email' => $aide->email,
                'subject' => $rendu['subject'],
                'status' => EmailLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'data' => $logData,
            ]);

            return false;
        }
    }
}
