<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\Transaction;
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
        return (new MailMessage)
            ->subject('Nouveau message de '.($this->message->sender?->name ?? 'Système'))
            ->markdown('emails.new_message', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
                'message' => $this->message,
            ]);
    }
}
