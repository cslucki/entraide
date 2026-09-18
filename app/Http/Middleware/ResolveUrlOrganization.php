<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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

        // TASK-1601 — une URL courte de fonctionnalite (`/loops`) est l'URL de
        // l'Organization PAR DEFAUT. Un utilisateur connecte qui n'en est pas
        // membre n'a rien a y faire : on l'envoie sur SA forme canonique.
        if ($redirect = $this->canonicalOrganizationRedirect($request)) {
            return $redirect;
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

            // TASK-1601 — MESURE, et decision de NE PAS elargir ici.
            //
            // Resoudre depuis l'utilisateur authentifie a cet endroit corrige
            // aussi les chemins profonds (`/messages/{user}`) et les
            // fonctionnalites sans route bornee (`/search`). Mais cela change
            // le tenant que voient TOUTES les gardes cross-organization des
            // routes courtes : mesure faite, **8 tests** de TASK-1288 / 1289 /
            // 1291 rougissent, parce qu'ils defendent la semantique actuelle —
            // l'URL courte EST celle de l'Organization par defaut, et un
            // etranger y est refuse.
            //
            // Ce n'est donc pas un correctif, c'est une redefinition. Elle
            // demande son propre arbitrage. TASK-1601 se borne a la redirection
            // canonique en amont (`canonicalOrganizationRedirect()`), qui evite
            // le liage etranger sans toucher a cette semantique.
            return $this->resolveDefaultOrganization();
        }

        if (Auth::check()) {
            return $this->resolveFromAuthenticatedUser();
        }

        return null;
    }

    /**
     * TASK-1601 — la forme canonique de l'URL courte, pour qui n'est pas membre
     * de l'Organization par defaut.
     *
     * Quatre bornes, et elles comptent toutes :
     *
     * 1. **GET seulement.** Rediriger un POST perdrait son corps.
     * 2. **Racine de la fonctionnalite seulement** (`/loops`, pas
     *    `/loops/{uuid}`). `route('organization.loops.index')` ne sait pas
     *    reconstruire un segment profond ; le faire fabriquerait une URL
     *    fausse. Les chemins profonds sont deja corriges en amont : ils lient
     *    desormais l'Organization de l'utilisateur au lieu du defaut.
     * 3. **Organization par defaut exclue.** Pour son propre membre, l'URL
     *    courte EST deja la sienne : le rediriger ne corrigerait rien et
     *    changerait le comportement de tout le monde.
     * 4. **La route canonique doit EXISTER.** `search` et `reports` sont des
     *    fonctionnalites sans equivalent `/org/{organization}/…` : les
     *    rediriger fabriquerait un 404 la ou il n'y en avait pas.
     */
    protected function canonicalOrganizationRedirect(Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('GET') || ! Auth::check()) {
            return null;
        }

        $first = $request->segment(1);

        if ($first === null || $request->segment(2) !== null) {
            return null;
        }

        if (! in_array($first, static::$defaultOrganizationRoutes, true)) {
            return null;
        }

        if ($this->isAuthenticatedPersonalRoute($request)) {
            return null;
        }

        $organization = $this->resolveFromAuthenticatedUser();

        if (! $organization) {
            return null;
        }

        $default = $this->resolveDefaultOrganization();

        if ($default && $default->getKey() === $organization->getKey()) {
            return null;
        }

        $name = $this->canonicalRouteName($first);

        if ($name === null) {
            return null;
        }

        return redirect()->route($name, [
            'organization' => $organization->slug,
            ...$request->query(),
        ]);
    }

    /**
     * Le nom de route bornee qui correspond a une fonctionnalite, ou `null`
     * quand il n'en existe aucune. On n'essaie que les deux formes reellement
     * utilisees par `routes/web.php` — jamais une chaine devinee.
     */
    protected function canonicalRouteName(string $feature): ?string
    {
        foreach (['organization.'.$feature.'.index', 'organization.'.$feature] as $candidate) {
            if (Route::has($candidate)) {
                return $candidate;
            }
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
        // `first()` sans `orderBy` laisse le moteur choisir. Sur SQLite le
        // parcours suit l'ordre d'insertion et la reponse parait stable ; sur
        // PostgreSQL elle depend du plan, et deux Organizations actives
        // donnaient tantot l'une tantot l'autre — donc un **tenant arbitraire**
        // lie a la requete.
        //
        // Meme correction que `DefaultOrganizationResolver` (TASK-1121) et que
        // le departage des proprietaires (TASK-1117) : l'horodatage d'abord,
        // puis `id` pour trancher, `created_at` etant stocke a la seconde.
        $org = Organization::where('is_default', true)->orderBy('created_at')->orderBy('id')->first()
            ?? Organization::where('is_active', true)->orderBy('created_at')->orderBy('id')->first();

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
