<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $organization = currentOrganization()
            ?? $request->user()?->organization
            ?? DefaultOrganizationResolver::resolve();

        if (! $organization || ! $organization->subscriptions_enabled) {
            abort(404);
        }

        return view('subscriptions.index', [
            'organization' => $organization,
        ]);
    }

    public function orgIndex(string $slug): View
    {
        $organization = Organization::where('slug', $slug)->firstOrFail();

        if (! $organization->subscriptions_enabled) {
            abort(404);
        }

        return view('subscriptions.index', [
            'organization' => $organization,
        ]);
    }
}
