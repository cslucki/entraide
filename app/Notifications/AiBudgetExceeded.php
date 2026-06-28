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
        $template = SystemEmailTemplate::where('slug', 'ai_budget_exceeded')->where('enabled', true)->first();

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
}
