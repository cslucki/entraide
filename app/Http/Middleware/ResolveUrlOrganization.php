<?php

namespace App\Http\Middleware;

use App\Models\Community;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        'mentions-legales',
        'sitemap.xml',
        'logout',
    ];

    public static array $platformGlobalPrefixes = [
        'admin',
        'email',
        'password',
        'auth',
    ];

    public static array $defaultOrganizationRoutes = [
        'dashboard',
        'explorer',
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
    ];

    public static ?string $defaultOrganizationId = null;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->alreadyResolved()) {
            return $next($request);
        }

        if ($this->isCommunityPrefixedRoute($request)) {
            return $next($request);
        }

        if ($this->isPlatformGlobal($request)) {
            return $next($request);
        }

        $organization = $this->resolveOrganization($request);

        if ($organization) {
            $this->bindOrganization($organization);

            return $next($request);
        }

        if ($this->isKnownBusinessRoute($request)) {
            abort(404);
        }

        return $next($request);
    }

    protected function alreadyResolved(): bool
    {
        return app()->bound('current_organization') && app('current_organization') !== null;
    }

    protected function isCommunityPrefixedRoute(Request $request): bool
    {
        $route = $request->route();

        return $route && $route->hasParameter('community');
    }

    protected function isPlatformGlobal(Request $request): bool
    {
        $path = '/'.trim($request->path(), '/');

        if ($path === '/') {
            return true;
        }

        $first = $request->segment(1);

        if (!$first) {
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

    protected function resolveOrganization(Request $request): ?Community
    {
        $segments = $request->segments();
        $first = $segments[0] ?? null;
        $second = $segments[1] ?? null;

        if ($first && $second && $this->isFeatureRoute($second)) {
            $partnerOrg = $this->resolvePartnerOrganization($first);
            if ($partnerOrg) {
                return $partnerOrg;
            }
        }

        if ($first && $this->isFeatureRoute($first)) {
            if ($first === 'dashboard' && Auth::check()) {
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
        return in_array($segment, static::$defaultOrganizationRoutes);
    }

    protected function isKnownBusinessRoute(Request $request): bool
    {
        $first = $request->segment(1);

        if (!$first) {
            return false;
        }

        return in_array($first, static::$defaultOrganizationRoutes);
    }

    protected function resolveDefaultOrganization(): ?Community
    {
        if (static::$defaultOrganizationId) {
            $org = Community::find(static::$defaultOrganizationId);
            if ($org) {
                return $org;
            }
        }

        $defaultId = Setting::get('default_organization_id');
        if ($defaultId) {
            $org = Community::find($defaultId);
            if ($org) {
                return $org;
            }
        }

        return Community::where('is_active', true)->first();
    }

    protected function resolveFromAuthenticatedUser(): ?Community
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        if ($user->community_id) {
            return Community::find($user->community_id);
        }

        return null;
    }

    protected function resolvePartnerOrganization(string $slug): ?Community
    {
        // Out of scope for T075.2.
        // Partner — Organization resolution is a future task.
        return null;
    }

    protected function bindOrganization(Community $organization): void
    {
        app()->instance('current_organization', $organization);

        if (!app()->bound('current_community')) {
            app()->instance('current_community', $organization);
        }
    }
}
