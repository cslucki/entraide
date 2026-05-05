<?php

namespace App\Http\Controllers;

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
        $members = User::withCount([
                'services as active_services_count'         => fn($q) => $q->where('status', 'active'),
                'serviceRequests as open_requests_count'    => fn($q) => $q->where('status', 'open'),
            ])
            ->with(['services' => fn($q) => $q->where('status', 'active')->with('skills', 'category')])
            ->orderByDesc('created_at')
            ->paginate(16);

        return view('members.index', compact('members'));
    }

    public function exchanges(): View
    {
        $exchanges = Transaction::where('status', 'completed')
            ->with(['buyer', 'seller', 'service.category', 'serviceRequest', 'reviews'])
            ->latest('updated_at')
            ->paginate(20);

        return view('exchanges.index', compact('exchanges'));
    }
}
