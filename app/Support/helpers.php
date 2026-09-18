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

if (! function_exists('bp_themes')) {
    /**
     * TASK-1471 — LA source des themes BouclePro, une fois.
     *
     * Le meme bloc (cache `storage/app/bouclepro-themes.php`, repli
     * `config/bouclepro_themes.php`, extraction de `_meta.default`) etait
     * recopie a l'identique dans `layouts/app` et `layouts/org-admin`. Deux
     * copies avant meme que les landings publiques n'en demandent une
     * troisieme.
     *
     * Cette fonction ne DECIDE rien : elle lit la source existante, exactement
     * comme les layouts le faisaient. Aucune nouvelle autorite de theme.
     *
     * @return array{themes: array<string, array<string, mixed>>, default: string}
     */
    function bp_themes(): array
    {
        $cachePath = storage_path('app/bouclepro-themes.php');

        if (file_exists($cachePath)) {
            $themes = require $cachePath;
            $default = $themes['_meta']['default'] ?? config('bouclepro_themes.default', 'zen');
            unset($themes['_meta']);

            return ['themes' => $themes, 'default' => $default];
        }

        return [
            'themes' => config('bouclepro_themes.themes'),
            'default' => config('bouclepro_themes.default', 'zen'),
        ];
    }
}

if (! function_exists('bp_organization_theme_key')) {
    /**
     * TASK-1471 — la cle de theme d'une Organization, ou celle par defaut.
     *
     * Meme resolution que les deux layouts : `Organization.theme_id` ->
     * `theme()` -> `key`. Sur une landing publique, elle est posee cote
     * SERVEUR sur `<html data-bp-theme>` : un visiteur anonyme doit voir les
     * couleurs de l'Organization qu'il consulte, pas une preference de theme
     * laissee dans son navigateur par une autre page.
     */
    function bp_organization_theme_key(?\App\Models\Organization $organization = null): string
    {
        $organization ??= app()->bound('current_organization') ? app('current_organization') : null;

        return (string) ($organization?->theme?->key ?: bp_themes()['default']);
    }
}
