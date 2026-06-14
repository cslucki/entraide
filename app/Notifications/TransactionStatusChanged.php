<?php

namespace App\Notifications;

use App\Models\Transaction;
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
        return (new MailMessage)
            ->subject('Mise à jour de votre échange — '.$this->transaction->status_label)
            ->markdown('emails.transaction_status', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
            ]);
    }
}
