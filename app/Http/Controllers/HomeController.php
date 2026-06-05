<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Scopes\BelongsToOrganizationScope;
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

        $categories = Category::orderBy('name_b2c')->get();

        return view('home', compact('stats', 'featuredServices', 'categories'));
    }

    public function members(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            return view('members.setup-required');
        }

        $organizationId = $organization->id;

        $members = User::where('organization_id', $organizationId)
            ->withCount([
                'services as active_services_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'active')->where('organization_id', $organizationId),
                'serviceRequests as open_requests_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'open')->where('organization_id', $organizationId),
            ])
            ->with(['services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'active')->where('organization_id', $organizationId)->with('skills', 'category')])
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

        $organizationId = $organization->id;

        $exchanges = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('status', 'completed')
            ->where('organization_id', $organizationId)
            ->with(['buyer', 'seller', 'service.category', 'serviceRequest', 'reviews'])
            ->latest('updated_at')
            ->paginate(20);

        return view('exchanges.index', compact('exchanges'));
    }
}
