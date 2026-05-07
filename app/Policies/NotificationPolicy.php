<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class NotificationPolicy
{
    /**
     * Determine whether the user can update the notification.
     */
    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $user->id === $notification->notifiable_id &&
               $notification->notifiable_type === User::class;
    }
}
