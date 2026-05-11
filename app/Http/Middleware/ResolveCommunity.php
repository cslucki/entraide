<?php

namespace App\Http\Middleware;

use App\Models\Community;
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
            $community = Community::findBySlug($slug);
            if (! $community) {
                abort(404);
            }
            app()->instance('current_community', $community);
            app()->instance('current_organization', $community);
            View::share('currentCommunity', $community);
            View::share('currentOrganization', $community);
        } else {
            View::share('currentCommunity', null);
            View::share('currentOrganization', null);
        }

        return $next($request);
    }
}
