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

        // TASK-1694 bug G — le repli nomme l'ORGANIZATION, plus la marque.
        return (new MailMessage)
            ->subject(__('Bienvenue sur :organisation !', [
                // Un destinataire ANONYME (`AnonymousNotifiable`, route mail
                // directe) n'a ni `organization` ni meme la propriete : lire
                // `$notifiable->organization` dessus leverait. Le nom de
                // l'application est le repli — un email sans nom du tout serait
                // pire qu'un nom approximatif.
                'organisation' => ($notifiable instanceof \App\Models\User
                    ? $notifiable->organization?->name
                    : null) ?: config('app.name'),
            ]))
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

        // TASK-1694 bug G — la ligne GLOBALE, enfin atteignable.
        //
        // DEFAUT MESURE : des qu'une personne appartenait a une Organization —
        // c'est-a-dire TOUJOURS — la resolution ne regardait QUE les lignes de
        // ce tenant, puis retombait sur le gabarit Blade. Une Organization
        // creee APRES le seeder n'a aucune ligne : OFSH en avait zero, et
        // c'est le Blade qui partait, avec la marque en dur.
        //
        // La ligne globale existait donc sans que PERSONNE ne puisse la lire.
        // Un gabarit « Global » que l'administration affiche mais que le
        // produit n'emploie jamais est un mensonge d'interface.
        //
        // L'ordre reste : tenant dans la langue, tenant dans la langue par
        // defaut, PUIS global. Une Organization qui a personnalise garde sa
        // version — le global ne la recouvre jamais.
        return (clone $query)->whereNull('organization_id')->where('locale', $locale)->first()
            ?? (clone $query)->whereNull('organization_id')->where('locale', $defaultLocale)->first()
            ?? (clone $query)->whereNull('organization_id')->whereNull('locale')->first();
    }
}
