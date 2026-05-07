<?php

namespace App\Http\Controllers;

use App\Models\Community;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(string $community): View
    {
        $communityModel = Community::findBySlug($community);
        $communityId = $communityModel ? $communityModel->id : session('community_id');

        $notifications = Auth::user()->notifications()
            ->where('data->community_id', $communityId)
            ->latest()
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(string $community, string $id)
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $this->authorize('update', $notification);

        $notification->markAsRead();
        return back()->with('success', 'Notification marquée comme lue.');
    }

    public function markAllAsRead(string $community)
    {
        $communityModel = Community::findBySlug($community);
        $communityId = $communityModel ? $communityModel->id : session('community_id');

        Auth::user()->unreadNotifications()
            ->where('data->community_id', $communityId)
            ->get()
            ->markAsRead();

        return back()->with('success', 'Toutes les notifications ont été marquées comme lues.');
    }
}
