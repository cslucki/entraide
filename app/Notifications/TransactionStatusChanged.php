<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransactionStatusChanged extends Notification
{
    public function __construct(public readonly Transaction $transaction) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = SystemEmailTemplate::where('slug', 'transaction_status_changed')->where('enabled', true)->first();

        if ($template) {
            $service = $this->transaction->service;
            $title = $service ? $service->title : ($this->transaction->serviceRequest?->title ?? 'Échange');

            $emailer = app(EmailerService::class);
            $extraVars = [
                'title' => $title,
                'status_label' => $this->transaction->status_label,
                'points' => (string) ($this->transaction->points_agreed ?? $this->transaction->points_proposed),
                'url' => route('messages.show', $this->transaction),
            ];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars)))
                ->view('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        return (new MailMessage)
            ->subject('Mise à jour de votre échange — '.$this->transaction->status_label)
            ->markdown('emails.transaction_status', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
            ]);
    }
}
