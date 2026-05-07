<?php

namespace App\Notifications;

use App\Models\Badge;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BadgeEarned extends Notification
{
    use Queueable;

    public function __construct(public readonly Badge $badge) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'badge',
            'title' => 'Nouveau badge gagné !',
            'message' => 'Félicitations, vous avez débloqué le badge : ' . $this->badge->name,
            'action_url' => route('profile.show', $notifiable),
            'badge_id' => $this->badge->id,
            'badge_key' => $this->badge->key,
            'community_id' => $notifiable->community_id,
        ];
    }
}
