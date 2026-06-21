<?php

namespace App\Http\Middleware;

use App\Models\Organization;
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
        $orgId = $user->organization_id;

        if (! $orgId) {
            return null;
        }

        return Organization::whereKey($orgId)
            ->where('is_active', true)
            ->first();
    }

    protected function resolveDefault(): ?Organization
    {
        return Organization::where('is_default', true)->first()
            ?? Organization::where('is_active', true)->first();
    }

    protected function bindOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }
}
