<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OrgAdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $organization = $request->route('organization');

        if (! $organization || ! auth()->check()) {
            abort(403);
        }

        $user = auth()->user();

        $isOrgAdmin = $organization->admin_id === $user->id;
        $isGlobalAdmin = $user->is_admin;

        if (! $isOrgAdmin && ! $isGlobalAdmin) {
            abort(403);
        }

        return $next($request);
    }
}
