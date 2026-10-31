<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TASK-1734 — l'email qui porte le bouton de premiere association.
 *
 * ## Ce que cet email EST : la preuve elle-meme
 *
 * Il part vers l'adresse que les deux comptes partagent, et son URL contient
 * le jeton a usage unique ({@see \App\Services\Users\AccountLinkEmailProof}).
 * Cliquer ne suffit pas : le lien ouvre une page qui exige la session du
 * compte SOURCE et un POST — le GET ne consomme rien, pour survivre aux
 * scanners de boite qui prechargent les liens.
 *
 * ## PAS de file d'attente
 *
 * Volontairement sans `ShouldQueue` : la PROD tourne en `QUEUE=sync`, et cet
 * email est le coeur du parcours — le differer n'apporterait rien, le perdre
 * casserait tout. Il s'envoie HORS de toute transaction (lecon A6).
 *
 * ## Gabarit GLOBAL, comme l'email de verification
 *
 * Meme patron que {@see VerifyEmailWithTemplate} : resolution par slug en
 * global delibere (cet email traverse les tenants, aucune Organization n'a a
 * le surcharger), langue de la personne, repli sur un message en dur plutot
 * qu'un email vide.
 */
class AccountLinkConfirmationRequested extends Notification
{
    public const SLUG = 'liaison-comptes-confirmation';

    public function __construct(
        private readonly User $cible,
        private readonly string $jeton,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('my-organizations.confirm', ['token' => $this->jeton]);

        $extra = [
            'url' => $url,
            'source_organization' => $notifiable->organization?->name ?? '',
            'target_organization' => $this->cible->organization?->name ?? '',
        ];

        $template = $this->resolveTemplate($notifiable);

        if ($template === null) {
            return (new MailMessage)
                ->subject(trans('auth.myorg_mail_confirm_subject'))
                ->line(trans('auth.myorg_mail_confirm_line', [
                    'source' => $extra['source_organization'],
                    'target' => $extra['target_organization'],
                ]))
                ->action(trans('auth.myorg_mail_confirm_action'), $url)
                ->line(trans('auth.myorg_mail_confirm_ignore'));
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
