<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Temporaire (verification TASK-1384) — a supprimer.
 */
class ZzTmp1384Broken extends Notification
{
    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        throw new RuntimeException('gabarit casse');
    }
}
