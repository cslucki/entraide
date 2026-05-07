<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class NotificationCenter extends Component
{
    public function markAsRead($id)
    {
        $notification = Auth::user()->notifications()->findOrFail($id);

        if (Auth::user()->can('update', $notification)) {
            $notification->markAsRead();
        }
    }

    public function markAllAsRead()
    {
        $communityId = session('community_id');
        if (!$communityId) {
            return;
        }

        Auth::user()->unreadNotifications()
            ->where('data->community_id', $communityId)
            ->get()
            ->markAsRead();
    }

    public function render()
    {
        $communityId = session('community_id');

        if (!$communityId) {
            return <<<'HTML'
                <div></div>
            HTML;
        }

        $query = Auth::user()->notifications()
            ->where('data->community_id', $communityId);

        $notifications = (clone $query)->latest()->paginate(10);
        $unreadCount = (clone $query)->whereNull('read_at')->count();

        return view('livewire.notification-center', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }
}
