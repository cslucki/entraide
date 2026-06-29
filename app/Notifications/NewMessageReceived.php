<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageReceived extends Notification
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly Message $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('new_message', $notifiable);

        if ($template) {
            $senderName = $this->message->sender?->name ?? 'Système';
            $service = $this->transaction->service;
            $transactionTitle = $service ? $service->title : ($this->transaction->serviceRequest?->title ?? 'Échange');
            $messagePreview = mb_strimwidth(strip_tags($this->message->body ?? ''), 0, 120, '…');

            $emailer = app(EmailerService::class);
            $extraVars = [
                'sender_name' => $senderName,
                'transaction_title' => $transactionTitle,
                'message_preview' => $messagePreview,
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
            ->subject('Nouveau message de '.($this->message->sender?->name ?? 'Système'))
            ->markdown('emails.new_message', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
                'message' => $this->message,
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
