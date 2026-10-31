<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TASK-1734 — l'avis « vos comptes sont lies », avec le rappel de revocation.
 *
 * Part a CHAQUE creation de lien, quelle que soit la porte (preuve par la
 * boite, ou formulaire a deux mots de passe) : si quelqu'un d'autre a obtenu
 * cette liaison, c'est cet email qui le dit — et il dit aussi ou la defaire.
 *
 * Sans `ShouldQueue`, hors transaction, gabarit global : memes raisons que
 * {@see AccountLinkConfirmationRequested}.
 */
class AccountLinkEstablished extends Notification
{
    public const SLUG = 'liaison-comptes-creee';

    public function __construct(private readonly User $autreCompte) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $extra = [
            'url' => route('account.links'),
            'other_organization' => $this->autreCompte->organization?->name ?? '',
        ];

        $template = $this->resolveTemplate($notifiable);

        if ($template === null) {
            return (new MailMessage)
                ->subject(trans('auth.myorg_mail_linked_subject'))
                ->line(trans('auth.myorg_mail_linked_line', [
                    'other' => $extra['other_organization'],
                ]))
                ->action(trans('auth.myorg_mail_linked_action'), $extra['url']);
        }

        $emailer = app(EmailerService::class);
        $vars = array_merge($emailer->availableVariables($notifiable), $extra);

        return (new MailMessage)
            ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extra)))
            ->markdown('emails.system-email', [
                'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extra)),
            ]);
    }

    private function resolveTemplate(object $notifiable): ?SystemEmailTemplate
    {
        return app(SystemEmailTemplateResolver::class)->resolve(
            self::SLUG,
            null,
            $notifiable->preferred_locale ?? null,
        );
    }
}
