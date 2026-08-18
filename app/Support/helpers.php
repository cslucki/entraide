<?php

use App\Models\Organization;
use App\Services\TranslationOverrideService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Route;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;

if (! function_exists('currentOrganization')) {
    function currentOrganization()
    {
        return CurrentOrganization::get();
    }
}

if (! function_exists('organizationRoute')) {
    /**
     * Generate a URL for an organization route.
     *
     * Usage:
     *   organizationRoute('organization.home', ['organization' => $slug])
     *
     * @param  string  $name  Route name (e.g. 'organization.home')
     * @param  array  $parameters  Route parameters
     */
    function organizationRoute(string $name, array $parameters = []): string
    {
        return route($name, $parameters);
    }
}

if (! function_exists('aiOffersUrl')) {
    /**
     * TASK-1229 : URL de la page « Voir les offres » (information, sans
     * paiement), dans le prefixe d'Organization courant quand il y en a un —
     * meme regle que les autres liens de profil (`profile.ai-usage`).
     */
    function aiOffersUrl(?Organization $organization = null): string
    {
        $slug = request()->route('organization');

        if ($slug instanceof Organization) {
            $slug = $slug->slug;
        }

        $slug = $slug ?: $organization?->slug;

        if ($slug && ! (currentOrganization()?->is_default ?? false) && Route::has('organization.profile.ai-offers')) {
            return route('organization.profile.ai-offers', ['organization' => $slug]);
        }

        return route('profile.ai-offers');
    }
}

if (! function_exists('canonicalHome')) {
    function canonicalHome(Organization $organization): string
    {
        if ($organization->is_default) {
            if ($organization->loops_enabled) {
                return route('loops.index', absolute: false);
            }

            return '/';
        }

        if ($organization->loops_enabled) {
            return route('organization.loops.index', [
                'organization' => $organization->slug,
            ], absolute: false);
        }

        return route('organization.home', [
            'organization' => $organization->slug,
        ], absolute: false);
    }
}

if (! function_exists('markdown')) {
    function markdown(string $text, array $options = []): string
    {
        $config = array_merge([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br />\n"],
        ], $options);

        $converter = new CommonMarkConverter($config);
        $converter->getEnvironment()->addExtension(new GithubFlavoredMarkdownExtension);

        return (string) $converter->convert($text);
    }
}

if (! function_exists('org_trans')) {
    function org_trans(string $key, ?Organization $organization = null, array $replace = []): string
    {
        $organization ??= app()->bound('current_organization') ? app('current_organization') : null;

        if ($organization === null) {
            return __($key, $replace);
        }

        $parts = explode('.', $key, 2);
        $group = $parts[0];
        $item = $parts[1] ?? '';

        return app(TranslationOverrideService::class)->get(
            group: $group,
            key: $item,
            locale: app()->getLocale(),
            organization: $organization,
            replace: $replace,
        );
    }
}
