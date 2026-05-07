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
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nouveau message de ' . ($this->message->sender?->name ?? 'Système'))
            ->markdown('emails.new_message', [
                'user'        => $notifiable,
                'transaction' => $this->transaction,
                'message'     => $this->message,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        // Use the community relationship from transaction if loaded, otherwise fallback to session/user
        $communitySlug = $this->transaction->community?->slug ?? session('community_slug');

        return [
            'type' => 'message',
            'title' => 'Nouveau message',
            'message' => 'Vous avez reçu un nouveau message de ' . ($this->message->sender?->name ?? 'Système'),
            'action_url' => $communitySlug
                ? route('community.messages.show', ['community' => $communitySlug, 'transaction' => $this->transaction])
                : route('messages.show', $this->transaction),
            'transaction_id' => $this->transaction->id,
            'community_id' => $this->transaction->community_id,
        ];
    }
}
