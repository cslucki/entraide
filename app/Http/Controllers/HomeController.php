<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $stats = [
            'users'     => User::count(),
            'services'  => Service::where('status', 'active')->count(),
            'requests'  => ServiceRequest::where('status', 'open')->count(),
            'exchanges' => Transaction::where('status', 'completed')->count(),
        ];

        $featuredServices = Service::where('status', 'active')
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        return view('home', compact('stats', 'featuredServices'));
    }

    public function members(): View
    {
        $communityId = $this->currentCommunityId();

        $members = User::when($communityId, fn($q) => $q->where('community_id', $communityId))
            ->withCount([
                'services as active_services_count'      => fn($q) => $q->withoutGlobalScopes()->where('status', 'active')->where('community_id', $communityId),
                'serviceRequests as open_requests_count' => fn($q) => $q->withoutGlobalScopes()->where('status', 'open')->where('community_id', $communityId),
            ])
            ->with(['services' => fn($q) => $q->withoutGlobalScopes()->where('status', 'active')->where('community_id', $communityId)->with('skills', 'category')])
            ->orderByDesc('created_at')
            ->paginate(16);

        return view('members.index', compact('members'));
    }

    public function boucles(): View
    {
        $communities = Community::where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('boucles.index', compact('communities'));
    }

    public function exchanges(): View
    {
        $communityId = $this->currentCommunityId();

        $exchanges = Transaction::withoutGlobalScopes()
            ->where('status', 'completed')
            ->where('community_id', $communityId)
            ->with(['buyer', 'seller', 'service.category', 'serviceRequest', 'reviews'])
            ->latest('updated_at')
            ->paginate(20);

        return view('exchanges.index', compact('exchanges'));
    }

    private function currentCommunityId(): ?string
    {
        try {
            return app('current_community')?->id;
        } catch (\Exception) {
            return null;
        }
    }
}
