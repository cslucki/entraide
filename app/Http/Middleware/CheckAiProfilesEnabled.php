<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class CheckAiProfilesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = currentOrganization();

        if ($organization && ! $organization->ai_profiles_enabled) {
            if ($organization->subscriptions_enabled) {
                $routeName = $organization->is_default && Route::has('subscriptions')
                    ? 'subscriptions'
                    : 'organization.subscriptions';

                return redirect()->route($routeName, ['organization' => $organization->slug]);
            }

            abort(404);
        }

        return $next($request);
    }
}
