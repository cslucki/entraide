<?php

namespace App\Services\Loops;

use App\Models\EmailLog;
use App\Models\LoopInvitation;
use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use App\Services\LoopInvitationService;
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
 *
 * ## TASK-1378 — ce mailer ne sert plus les membres de l'Organization
 *
 * Depuis le cutover, une invitation adressee a un membre de l'Organization part
 * par le pipeline Notifications : notification IN_APP, livraison EMAIL, file
 * dediee, preuve dans `email_logs`. Ce mailer reste la voie des INCONNUS — pas
 * de compte, ou un compte dans un autre tenant — pour qui aucune notification
 * ne peut exister.
 *
 * Le garde est ICI, et pas chez les appelants. Deux chemins de production
 * appellent `send()` — le controleur et la Card Membres — et un troisieme
 * arrivera. Poser la condition chez chacun serait une convention : elle tiendrait
 * jusqu'a ce que quelqu'un l'oublie, et l'oubli enverrait un email en double
 * sans rien casser de visible. Ici, le double envoi est structurellement
 * impossible, et tout futur appelant herite de la protection.
 */
class LoopInvitationMailer
{
    public function send(LoopInvitation $invitation): void
    {
        // Une seule autorite decide qui envoie — et elle recalcule en vif, sans
        // faire confiance a `invitation_type`, qui est fige a la creation.
        if (app(LoopInvitationService::class)->emailHandledByNotifications($invitation)) {
            return;
        }

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
