<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AiBudgetExceeded extends Notification
{
    public function __construct(
        public readonly string $scenarioId,
        public readonly float $currentCost,
        public readonly float $budgetLimit,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('ai_budget_exceeded', $notifiable);

        if ($template) {
            $emailer = app(EmailerService::class);
            $extraVars = [
                'scenario_id' => $this->scenarioId,
                'current_cost' => (string) $this->currentCost,
                'budget_limit' => (string) $this->budgetLimit,
                'url' => route('explorer'),
            ];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars)))
                ->view('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        return (new MailMessage)
            ->subject('Alerte budget IA — '.$this->scenarioId)
            ->markdown('emails.ai_budget_exceeded', [
                'user' => $notifiable,
                'scenarioId' => $this->scenarioId,
                'currentCost' => $this->currentCost,
                'budgetLimit' => $this->budgetLimit,
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
