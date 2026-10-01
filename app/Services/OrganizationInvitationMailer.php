<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\OrganizationInvitation;
use App\Models\SystemEmailTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The invitation e-mail for TASK-1659, on the exact pattern of
 * LoopInvitationMailer: an administrable SystemEmailTemplate first, an
 * explicit logged Blade fallback, a synchronous Mail::html() send (the only
 * kind this repository's local mail path can prove — see T1659_GROUNDED),
 * and a trace in email_logs either way.
 */
class OrganizationInvitationMailer
{
    /**
     * L'URL d'atterrissage mise dans le courriel.
     *
     * Par defaut l'URL canonique du depot. Avec un « Host de test »
     * (local/testing seulement), seule la BASE change : le chemin et le
     * jeton restent generes par Laravel, puis sont prefixes par l'origin
     * valide. Le jeton ne vient donc jamais du host saisi.
     *
     * Aucun `URL::forceRootUrl()`, aucune ecriture dans la configuration :
     * la substitution est locale a cet appel, et ne peut pas fuir sur une
     * autre URL de la meme requete.
     *
     * L'override stocke est RE-VERIFIE ici — environnement et forme. Une
     * ligne ecrite en testing, ou un environnement bascule depuis, ne doit
     * pas pouvoir envoyer un vrai courriel vers un tunnel.
     */
    private function landingUrlFor(OrganizationInvitation $invitation): string
    {
        $canonique = route('organization-invitations.show', $invitation->token);

        if (! OrganizationInvitation::hostOverrideAllowed()) {
            return $canonique;
        }

        $origin = OrganizationInvitation::normalizeHostOverride($invitation->host_override);

        if ($origin === null) {
            return $canonique;
        }

        return $origin.route('organization-invitations.show', $invitation->token, absolute: false);
    }

    public function send(OrganizationInvitation $invitation): void
    {
        $organization = $invitation->organization;
        $sender = $invitation->createdBy;
        $landingUrl = $this->landingUrlFor($invitation);

        // The language the SuperAdmin chose for THIS invitation (French by
        // default). Deliberately NOT app()->getLocale(): that is the
        // SuperAdmin's own session language, which says nothing about the
        // language the invited person reads — and not the Organization's
        // locale either, since one Organization can welcome people who read
        // different languages.
        $locale = in_array($invitation->locale, OrganizationInvitation::LOCALES, true)
            ? $invitation->locale
            : OrganizationInvitation::DEFAULT_LOCALE;

        $template = SystemEmailTemplate::where('slug', 'organization_invitation')
            ->where('enabled', true)
            ->where('organization_id', $invitation->organization_id)
            ->where('locale', $locale)
            ->first();

        $emailer = app(EmailerService::class);
        $extraKeys = [
            'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
            'invitation_url', 'expires_at', 'app_name',
        ];
        $vars = array_merge(
            $sender ? $emailer->availableVariables($sender) : [],
            [
                'recipient_name' => $invitation->recipientFullName(),
                'recipient_email' => $invitation->recipient_email,
                'sender_name' => $sender?->fullName ?? '',
                'organization_name' => $organization?->name ?? '',
                'invitation_url' => $landingUrl,
                // ->locale() formats just this value in the Organization's
                // language, without touching Carbon's global/default locale
                // (isoFormat() alone follows that global state, not $locale).
                'expires_at' => $invitation->expires_at?->locale($locale)->isoFormat('LL') ?? '',
                'app_name' => config('app.name'),
            ],
        );

        $fallbackReason = null;

        if ($template) {
            try {
                $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
                $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
            } catch (\Throwable $e) {
                $fallbackReason = 'render_error: '.$e->getMessage();
            }
        } else {
            $fallbackReason = SystemEmailTemplate::where('slug', 'organization_invitation')
                ->where('organization_id', $invitation->organization_id)
                ->where('locale', $locale)
                ->exists()
                ? 'template_disabled'
                : 'template_missing';
        }

        if ($fallbackReason !== null) {
            Log::warning('organization_invitation e-mail fell back to the Blade template', [
                'reason' => $fallbackReason,
                'invitation_id' => $invitation->id,
                'organization_id' => $invitation->organization_id,
                'locale' => $locale,
            ]);

            // The Blade fallback reads __() at render time, which follows
            // the APP's current locale, not a parameter — switch to the
            // Organization's locale only for this render, then restore the
            // SuperAdmin's own session locale immediately after.
            $requestLocale = app()->getLocale();
            app()->setLocale($locale);
            try {
                $subject = __('organization_invitations.mail_subject', ['organization' => $organization?->name]);
                $html = view('emails.organization-invitation', [
                    'invitation' => $invitation,
                    'organization' => $organization,
                    'sender' => $sender,
                    'landingUrl' => $landingUrl,
                ])->render();
            } finally {
                app()->setLocale($requestLocale);
            }
        }

        $logData = [
            'source' => 'organization-invitation',
            'invitation_id' => $invitation->id,
            'organization_id' => $invitation->organization_id,
            'recipient_email' => $invitation->recipient_email,
            'template_used' => $fallbackReason === null ? 'system_email_template' : 'blade_fallback',
            'fallback_reason' => $fallbackReason,
        ];

        try {
            Mail::html($html, function ($message) use ($invitation, $subject) {
                $message->to($invitation->recipient_email)->subject($subject);
            });

            EmailLog::create([
                'user_id' => $invitation->created_by_user_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'sent',
                'data' => $logData,
            ]);
        } catch (\Throwable $e) {
            // The invitation row is already persisted and its link stays
            // valid, so a delivery failure is recoverable by resending; it
            // must be recorded rather than swallowed.
            EmailLog::create([
                'user_id' => $invitation->created_by_user_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => $logData,
            ]);
        }
    }
}
