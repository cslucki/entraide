<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user) {
            $organization = $this->resolveFromAuthenticatedUser($user);

            if (! $organization) {
                return response()->json(['message' => 'Organization access denied.'], 403);
            }

            $this->bindOrganization($organization);

            return $next($request);
        }

        $organization = $this->resolveDefault();

        if ($organization) {
            $this->bindOrganization($organization);
        }

        return $next($request);
    }

    protected function resolveFromAuthenticatedUser(object $user): ?Organization
    {
        if (! $user->community_id) {
            return null;
        }

        // Legacy DB tenant column: community_id currently stores the Organization id.
        return Organization::whereKey($user->community_id)
            ->where('is_active', true)
            ->first();
    }

    protected function resolveDefault(): ?Organization
    {
        $defaultId = Setting::get('default_organization_id');

        if ($defaultId) {
            $org = Organization::whereKey($defaultId)
                ->where('is_active', true)
                ->first();

            if ($org) {
                return $org;
            }
        }

        return Organization::where('is_active', true)->first();
    }

    protected function bindOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }
}
