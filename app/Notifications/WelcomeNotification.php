<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = SystemEmailTemplate::where('slug', 'welcome')->where('enabled', true)->first();

        if ($template) {
            $emailer = app(EmailerService::class);
            $extraVars = ['url' => route('explorer')];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars)))
                ->view('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        return (new MailMessage)
            ->subject('Bienvenue sur Entraide !')
            ->markdown('emails.welcome', [
                'user' => $notifiable,
            ]);
    }
}
