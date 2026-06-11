<?php

namespace App\Notifications;

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
        return (new MailMessage)
            ->subject('Alerte budget IA — ' . $this->scenarioId)
            ->markdown('emails.ai_budget_exceeded', [
                'user' => $notifiable,
                'scenarioId' => $this->scenarioId,
                'currentCost' => $this->currentCost,
                'budgetLimit' => $this->budgetLimit,
            ]);
    }
}
