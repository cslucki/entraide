<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    protected array $supportedLocales = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        App::setLocale($locale);

        return $next($request);
    }

    protected function resolveLocale(Request $request): string
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;

        if ($sessionLocale && in_array($sessionLocale, $this->supportedLocales, true)) {
            return $sessionLocale;
        }

        $userLocale = $this->resolveUserLocale($request);

        if ($userLocale !== null) {
            return $userLocale;
        }

        $orgLocale = $this->resolveOrganizationLocale();

        if ($orgLocale !== null) {
            return $orgLocale;
        }

        $browserLocale = $this->resolveBrowserLocale($request);

        if ($browserLocale !== null) {
            return $browserLocale;
        }

        return config('app.locale', 'fr');
    }

    protected function resolveUserLocale(Request $request): ?string
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $locale = $user->preferred_locale;

        if ($locale && in_array($locale, $this->supportedLocales, true)) {
            return $locale;
        }

        return null;
    }

    protected function resolveOrganizationLocale(): ?string
    {
        if (! app()->bound('current_organization')) {
            return null;
        }

        $org = app('current_organization');

        if ($org && ! empty($org->locale) && in_array($org->locale, $this->supportedLocales, true)) {
            return $org->locale;
        }

        return null;
    }

    protected function resolveBrowserLocale(Request $request): ?string
    {
        $locale = $request->getPreferredLanguage($this->supportedLocales);

        if ($locale && in_array($locale, $this->supportedLocales, true)) {
            return $locale;
        }

        return null;
    }
}
