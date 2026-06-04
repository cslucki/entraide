<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLoopsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = currentOrganization();

        if ($organization && !$organization->loops_enabled) {
            abort(404);
        }

        return $next($request);
    }
}
