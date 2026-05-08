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

        // Fallback to notifiable community if report community is null
        $communitySlug = $this->report->community?->slug ?? ($notifiable->community?->slug ?? session('community_slug'));
        $communityId = $this->report->community_id ?? ($notifiable->community_id ?? session('community_id'));

        return [
            'type' => 'report',
            'title' => 'Signalement traité',
            'message' => 'Votre signalement a été ' . $statusLabel . ' par la modération.',
            'action_url' => $communitySlug
                ? route('community.dashboard', ['community' => $communitySlug])
                : route('dashboard'),
            'report_id' => $this->report->id,
            'status' => $this->report->status,
            'community_id' => $communityId,
        ];
    }
}
