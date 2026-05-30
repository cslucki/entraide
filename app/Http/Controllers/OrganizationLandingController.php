<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrganizationLandingController extends Controller
{
    public function __invoke(string $organization): View|RedirectResponse
    {
        $organization = Organization::findBySlug($organization);
        abort_if(! $organization || ! $organization->is_active, 404);

        if (! $organization->is_public && ! auth()->check()) {
            return redirect()->route('organization.login', ['organization' => $organization->slug]);
        }

        $recentServices = Service::where('organization_id', $organization->id)
            ->where('status', 'active')
            ->with(['user', 'category'])
            ->latest()
            ->take(6)
            ->get();

        $memberCount = $organization->users()->count();
        $serviceCount = $organization->services()->where('status', 'active')->count();
        $transactionCount = $organization->transactions()->where('status', 'completed')->count();

        return view('community.landing', compact('organization', 'recentServices', 'memberCount', 'serviceCount', 'transactionCount'));
    }
}
