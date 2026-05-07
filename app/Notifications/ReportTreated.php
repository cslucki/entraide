<?php

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReportTreated extends Notification
{
    use Queueable;

    public function __construct(public readonly Report $report) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $statusLabel = match ($this->report->status) {
            'reviewed' => 'traité',
            'dismissed' => 'classé sans suite',
            default => $this->report->status,
        };

        return [
            'type' => 'report',
            'title' => 'Signalement traité',
            'message' => 'Votre signalement a été ' . $statusLabel . ' par la modération.',
            'action_url' => '#',
            'report_id' => $this->report->id,
            'status' => $this->report->status,
            'community_id' => $notifiable->community_id,
        ];
    }
}
