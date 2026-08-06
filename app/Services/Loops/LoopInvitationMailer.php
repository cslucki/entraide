<?php

namespace App\Services\Loops;

use App\Models\EmailLog;
use App\Models\LoopInvitation;
use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * L'envoi du courriel d'invitation a une Boucle.
 *
 * Ce code vivait en prive dans LoopInvitationController et n'etait donc
 * atteignable que par la route POST. La Card Membres envoie maintenant la meme
 * invitation sans quitter l'ecran : le recopier aurait garanti que les deux
 * chemins divergent — sur le gabarit choisi, sur les variables interpolees, ou
 * sur ce qui est journalise en cas d'echec.
 *
 * Le corps est repris tel quel, y compris son repli sur le gabarit Blade et sa
 * trace en cas de non-remise.
 */
class LoopInvitationMailer
{
    public function send(LoopInvitation $invitation): void
    {
        $loop = $invitation->loop;
        $organization = $invitation->organization;
        $sender = $invitation->sender;
        $landingUrl = route('loop-invitations.show', $invitation->token);
        $personalMessage = filled($invitation->message)
            ? $invitation->message
            : __('loops.invitation_default_message', ['loop' => $loop?->name]);

        $template = SystemEmailTemplate::where('slug', 'loop_invitation')
            ->where('enabled', true)
            ->where('organization_id', $invitation->organization_id)
            ->where('locale', app()->getLocale())
            ->first();

        $emailer = app(EmailerService::class);
        $extraKeys = [
            'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
            'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
            'expires_at', 'app_name',
        ];
        $vars = array_merge(
            $sender ? $emailer->availableVariables($sender) : [],
            [
                'recipient_name' => $invitation->recipient_name ?: $invitation->recipient_email,
                'recipient_email' => $invitation->recipient_email,
                'sender_name' => $sender?->fullName ?? '',
                'organization_name' => $organization?->name ?? '',
                'loop_name' => $loop?->name ?? '',
                'loop_tagline' => $loop?->tagline ?? '',
                'personal_message' => $personalMessage,
                'invitation_url' => $landingUrl,
                'expires_at' => optional($invitation->expires_at)->isoFormat('LL') ?? '',
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
            $fallbackReason = SystemEmailTemplate::where('slug', 'loop_invitation')
                ->where('organization_id', $invitation->organization_id)
                ->where('locale', app()->getLocale())
                ->exists()
                ? 'template_disabled'
                : 'template_missing';
        }

        if ($fallbackReason !== null) {
            Log::warning('loop_invitation e-mail fell back to the Blade template', [
                'reason' => $fallbackReason,
                'invitation_id' => $invitation->id,
                'organization_id' => $invitation->organization_id,
                'locale' => app()->getLocale(),
            ]);

            $subject = __('loops.invitation_mail_subject', ['loop' => $loop?->name]);
            $html = view('emails.loop-invitation', [
                'invitation' => $invitation,
                'loop' => $loop,
                'organization' => $organization,
                'sender' => $sender,
                'personalMessage' => $personalMessage,
                'landingUrl' => $landingUrl,
            ])->render();
        }

        $logData = [
            'source' => 'loop-invitation',
            'invitation_id' => $invitation->id,
            'loop_id' => $invitation->loop_id,
            'organization_id' => $invitation->organization_id,
            'invitation_type' => $invitation->invitation_type,
            'recipient_email' => $invitation->recipient_email,
            'template_used' => $fallbackReason === null ? 'system_email_template' : 'blade_fallback',
            'fallback_reason' => $fallbackReason,
        ];

        try {
            Mail::html($html, function ($message) use ($invitation, $subject) {
                $message->to($invitation->recipient_email)->subject($subject);
            });

            EmailLog::create([
                'user_id' => $invitation->sender_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'sent',
                'data' => $logData,
            ]);
        } catch (\Throwable $e) {
            // The invitation row is already persisted and its link stays valid,
            // so a delivery failure is recoverable by re-sending; it must be
            // recorded rather than swallowed.
            EmailLog::create([
                'user_id' => $invitation->sender_id,
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
