<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveCommunity
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('community') ?? $request->route('organization');

        if ($slug) {
            $organization = Organization::findBySlug($slug);
            if (! $organization) {
                abort(404);
            }
            app()->instance('current_organization', $organization);
            app()->instance('current_community', $organization);
            View::share('currentOrganization', $organization);
            View::share('currentCommunity', $organization);
        } else {
            View::share('currentOrganization', null);
            View::share('currentCommunity', null);
        }

        return $next($request);
    }
}
