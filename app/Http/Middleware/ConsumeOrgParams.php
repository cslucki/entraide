<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConsumeOrgParams
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route) {
            $route->forgetParameter('organization');
            $route->forgetParameter('community');
        }

        return $next($request);
    }
}
