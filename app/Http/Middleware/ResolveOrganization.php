<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('community') ?? $request->route('organization');

        if ($slug) {
            if ($slug instanceof Organization) {
                $organization = $slug;
            } else {
                $organization = Organization::findBySlug($slug);
                if (! $organization) {
                    abort(404);
                }
            }
            app()->instance('current_organization', $organization);
            View::share('currentOrganization', $organization);
        }

        return $next($request);
    }
}
