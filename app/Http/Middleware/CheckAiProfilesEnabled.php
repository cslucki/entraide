<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAiProfilesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = currentOrganization();

        if ($organization && ! $organization->ai_profiles_enabled) {
            abort(404);
        }

        return $next($request);
    }
}
