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
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Mise à jour de votre échange — ' . $this->transaction->status_label)
            ->markdown('emails.transaction_status', [
                'user'        => $notifiable,
                'transaction' => $this->transaction,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'transaction',
            'title' => 'Mise à jour d\'échange',
            'message' => 'Le statut de votre échange pour « ' . $this->transaction->service->title . ' » est passé à : ' . $this->transaction->status_label,
            'action_url' => route('messages.show', $this->transaction),
            'transaction_id' => $this->transaction->id,
            'status' => $this->transaction->status,
            'community_id' => $this->transaction->community_id,
        ];
    }
}
