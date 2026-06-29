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
        $template = $this->resolveTemplate('welcome', $notifiable);

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

    private function resolveTemplate(string $slug, object $notifiable): ?SystemEmailTemplate
    {
        $organizationId = $notifiable->organization_id ?? null;
        $locale = $notifiable->preferred_locale ?? app()->getLocale();

        if (! $organizationId) {
            return SystemEmailTemplate::where('slug', $slug)->where('enabled', true)->first();
        }

        $organization = $notifiable->organization;

        $query = SystemEmailTemplate::where('slug', $slug)->where('enabled', true);

        $template = (clone $query)
            ->where('organization_id', $organizationId)
            ->where('locale', $locale)
            ->first();

        if ($template) {
            return $template;
        }

        $defaultLocale = $organization?->locale ?? 'fr';

        if ($locale !== $defaultLocale) {
            $template = (clone $query)
                ->where('organization_id', $organizationId)
                ->where('locale', $defaultLocale)
                ->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }
}
