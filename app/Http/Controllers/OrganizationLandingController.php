<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
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

        $stats = [
            'users' => $organization->users()->count(),
            'services' => Service::where('organization_id', $organization->id)->where('status', 'active')->count(),
            'requests' => $organization->serviceRequests()->where('status', 'open')->count(),
            'exchanges' => Transaction::where('organization_id', $organization->id)->where('status', 'completed')->count(),
        ];

        $featuredServices = Service::where('organization_id', $organization->id)
            ->where('status', 'active')
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        $categories = Category::where('organization_id', $organization->id)->orderBy('name_b2c')->get();

        if ($organization->homepage_template === 'bouclepro_hero_v2') {
            $heroAvatars = $organization->users()
                ->latest()
                ->limit(12)
                ->get(['id', 'name', 'avatar'])
                ->map(fn ($user) => $user->avatar_url)
                ->values();

            return view('organization.hero-v2', compact('organization', 'heroAvatars'));
        }

        if ($organization->homepage_template === 'artscilab_hero') {
            $heroAvatars = $organization->users()
                ->latest()
                ->limit(16)
                ->get(['id', 'name', 'avatar'])
                ->map(fn ($user) => $user->avatar_url)
                ->values();

            return view('organization.artscilab-hero', compact('organization', 'heroAvatars'));
        }

        $defaultOrganization = $organization;

        return view('organization.home', compact('organization', 'stats', 'featuredServices', 'categories', 'defaultOrganization'));
    }
}
