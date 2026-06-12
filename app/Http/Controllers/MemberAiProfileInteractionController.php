<?php

namespace App\Http\Controllers;

use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberAiProfileInteractionController extends Controller
{
    public function index(Request $request): View
    {
        $organization = currentOrganization()
            ?? $request->user()?->organization
            ?? DefaultOrganizationResolver::resolve();

        $profile = MemberAiProfile::query()
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $organization?->id)
            ->first();

        $interactions = MemberAiProfileInteraction::query()
            ->with('visitor')
            ->when($organization, fn ($query) => $query->forOrganization($organization))
            ->when($profile, fn ($query) => $query->where('member_ai_profile_id', $profile->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->latest()
            ->paginate(20);

        return view('agent-ia.interactions', [
            'profile' => $profile,
            'interactions' => $interactions,
        ]);
    }
}
