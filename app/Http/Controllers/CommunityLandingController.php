<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CommunityLandingController extends Controller
{
    public function __invoke(string $community): View|RedirectResponse
    {
        $community = Community::findBySlug($community);
        abort_if(!$community || !$community->is_active, 404);

        if (auth()->check()) {
            return redirect()->route('community.dashboard', ['community' => $community->slug]);
        }

        if (! $community->is_public) {
            return redirect()->route('community.login', ['community' => $community->slug]);
        }

        $recentServices = Service::where('community_id', $community->id)
            ->where('status', 'active')
            ->with(['user', 'category'])
            ->latest()
            ->take(6)
            ->get();

        $memberCount = $community->users()->count();
        $serviceCount = $community->services()->where('status', 'active')->count();
        $transactionCount = $community->transactions()->where('status', 'completed')->count();

        return view('community.landing', compact('community', 'recentServices', 'memberCount', 'serviceCount', 'transactionCount'));
    }
}
