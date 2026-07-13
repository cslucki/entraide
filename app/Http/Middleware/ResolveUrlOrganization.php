<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolveUrlOrganization
{
    public static array $platformGlobalExact = [
        '/',
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'confirm-password',
        'verify-email',
        'demo',
        'launchpals',
        'mentions-legales',
        'sitemap.xml',
        'logout',
    ];

    public static array $platformGlobalPrefixes = [
        'admin',
        'email',
        'password',
        'auth',
        'partners',
    ];

    public static array $defaultOrganizationRoutes = [
        'dashboard',
        'explorer',
        'agent-ia',
        'membres',
        'echanges',
        'boucles',
        'blog',
        'search',
        'services',
        'requests',
        'transactions',
        'loops',
        'messages',
        'points',
        'favorites',
        'profile',
        'reports',
        'flux',
    ];

    // Routes that require an authenticated user — guests are passed through
    // without org binding so the auth middleware can redirect them to login.
    public static array $authenticatedPersonalRoutes = [
        'dashboard',
    ];

    // Known public business pages that should show a setup-required page
    // instead of 404 when no Organization exists (empty or unseeded DB).
    public static array $passthroughNoOrgRoutes = [
        'explorer',
        'membres',
        'echanges',
        'boucles',
        'blog',
        'search',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->alreadyResolved()) {
            return $next($request);
        }

        if ($this->isOrganizationPrefixedRoute($request)) {
            return $next($request);
        }

        if ($this->isPlatformGlobal($request)) {
            return $next($request);
        }

        // Authenticated personal routes: guests pass through without org binding.
        // The auth middleware handles the redirect to login — no org resolution needed.
        if ($this->isAuthenticatedPersonalRoute($request) && ! Auth::check()) {
            return $next($request);
        }

        // Partner slug routes (/{slug}/{feature}): try to resolve, fail-safe 404
        // if the partner → Organization mapping is not found.
        // Partner model/table and full resolution are future tasks (T075.4+).
        if ($this->isPartnerSlugRoute($request)) {
            $partnerOrg = $this->resolvePartnerOrganization($request->segment(1));
            if (! $partnerOrg) {
                abort(404);
            }
            $this->bindOrganization($partnerOrg);

            return $next($request);
        }

        $organization = $this->resolveOrganization($request);

        if ($organization) {
            $this->bindOrganization($organization);

            return $next($request);
        }

        if ($this->isKnownBusinessRoute($request) && ! $this->isPassthroughNoOrgRoute($request)) {
            abort(404);
        }

        if ($this->isPassthroughNoOrgRoute($request) && $request->isMethod('GET')) {
            return response()->view('members.setup-required');
        }

        return $next($request);
    }

    protected function alreadyResolved(): bool
    {
        return app()->bound('current_organization') && app('current_organization') !== null;
    }

    protected function isOrganizationPrefixedRoute(Request $request): bool
    {
        $route = $request->route();

        return $route && $route->hasParameter('organization');
    }

    protected function isPlatformGlobal(Request $request): bool
    {
        $path = '/'.trim($request->path(), '/');

        if ($path === '/') {
            return true;
        }

        $first = $request->segment(1);

        if (! $first) {
            return true;
        }

        if (in_array($first, static::$platformGlobalExact)) {
            return true;
        }

        foreach (static::$platformGlobalPrefixes as $prefix) {
            if ($first === $prefix) {
                return true;
            }
        }

        return false;
    }

    protected function isAuthenticatedPersonalRoute(Request $request): bool
    {
        $first = $request->segment(1);

        return $first !== null && in_array($first, static::$authenticatedPersonalRoutes);
    }

    // Detects /{slug}/{feature} where the first segment is NOT itself a feature route.
    // This distinguishes partner slugs from nested feature paths like /dashboard/settings.
    protected function isPartnerSlugRoute(Request $request): bool
    {
        $first = $request->segment(1);
        $second = $request->segment(2);

        return $first !== null
            && $second !== null
            && ! $this->isFeatureRoute($first)
            && $this->isFeatureRoute($second);
    }

    protected function resolveOrganization(Request $request): ?Organization
    {
        $first = $request->segment(1);

        if ($first && $this->isFeatureRoute($first)) {
            if ($this->isAuthenticatedPersonalRoute($request)) {
                return $this->resolveFromAuthenticatedUser();
            }

            return $this->resolveDefaultOrganization();
        }

        if (Auth::check()) {
            return $this->resolveFromAuthenticatedUser();
        }

        return null;
    }

    protected function isFeatureRoute(string $segment): bool
    {
        if (str_starts_with($segment, 'livewire-')) {
            return true;
        }

        return in_array($segment, static::$defaultOrganizationRoutes);
    }

    protected function isKnownBusinessRoute(Request $request): bool
    {
        $first = $request->segment(1);

        if (! $first) {
            return false;
        }

        return in_array($first, static::$defaultOrganizationRoutes);
    }

    protected function isPassthroughNoOrgRoute(Request $request): bool
    {
        $first = $request->segment(1);

        if (! $first) {
            return false;
        }

        return in_array($first, static::$passthroughNoOrgRoutes);
    }

    protected function resolveDefaultOrganization(): ?Organization
    {
        $org = Organization::where('is_default', true)->first()
            ?? Organization::where('is_active', true)->first();

        if (! $org) {
            Log::warning('Default Organization resolution failed: no active organization with is_default = true in DB.');
        }

        return $org;
    }

    protected function resolveFromAuthenticatedUser(): ?Organization
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        $orgId = $user->organization_id;
        if ($orgId) {
            return Organization::find($orgId);
        }

        return null;
    }

    protected function resolvePartnerOrganization(string $slug): ?Organization
    {
        // Out of scope for T075.2.
        // Partner — Organization resolution is a future task (T075.4+).
        return null;
    }

    protected function bindOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }
}
