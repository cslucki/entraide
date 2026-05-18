<?php

namespace App\Http\Controllers;

use App\Models\Category;
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
            'users' => User::count(),
            'services' => Service::where('status', 'active')->count(),
            'requests' => ServiceRequest::where('status', 'open')->count(),
            'exchanges' => Transaction::where('status', 'completed')->count(),
        ];

        $featuredServices = Service::where('status', 'active')
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        $categories = Category::orderBy('name')->get();

        return view('home', compact('stats', 'featuredServices', 'categories'));
    }

    public function members(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $communityId = $organization->id;

        $members = User::where('community_id', $communityId)
            ->withCount([
                'services as active_services_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')->where('community_id', $communityId),
                'serviceRequests as open_requests_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'open')->where('community_id', $communityId),
            ])
            ->with(['services' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')->where('community_id', $communityId)->with('skills', 'category')])
            ->orderByDesc('created_at')
            ->paginate(16);

        return view('members.index', compact('members'));
    }

    public function boucles(): View
    {
        return view('boucles.index');
    }

    public function partners(): View
    {
        return view('partenaires.index');
    }

    public function exchanges(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $communityId = $organization->id;

        $exchanges = Transaction::withoutGlobalScopes()
            ->where('status', 'completed')
            ->where('community_id', $communityId)
            ->with(['buyer', 'seller', 'service.category', 'serviceRequest', 'reviews'])
            ->latest('updated_at')
            ->paginate(20);

        return view('exchanges.index', compact('exchanges'));
    }
}
