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

if (! function_exists('availabilityIsShown')) {
    /**
     * TASK-1694 bug AE — faut-il afficher la disponibilite des membres ?
     *
     * Une seule lecture, pour les CINQ surfaces qui portaient la pastille.
     * Cyril en a nomme trois et ajoute « et tous les autres endroits » :
     * l'inventaire du 24/10 en a trouve deux de plus, dont la fiche d'un
     * Service. Une condition recopiee cinq fois aurait fini par diverger, et
     * c'est justement la surface oubliee qui aurait garde le point vert.
     *
     * La question porte sur l'Organization COURANTE — le tenant dont on
     * regarde les pages — et non sur celle de la personne affichee : c'est
     * l'ecran qui decide de ce qu'il montre, pas la donnee.
     *
     * Hors tenant (pages sans Organization), la reponse est `true` : l'absence
     * de contexte n'est pas un « non », et faire disparaitre la fonctionnalite
     * par accident serait pire que la laisser.
     */
    function availabilityIsShown(): bool
    {
        return currentOrganization()?->availabilityEnabled() ?? true;
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

if (! function_exists('organizationScopedUrl')) {
    /**
     * TASK-1604 — un lien qui part d'une page `/org/{organization}/…` y reste.
     *
     * Usage :
     *   organizationScopedUrl('mycelium', 'organization.mycelium')
     *
     * LE DECLENCHEUR EST L'URL, jamais l'Organization liee au conteneur. Sur
     * une route GLOBALE courte, `ResolveUrlOrganization` lie tout de meme une
     * Organization — celle par defaut, ou celle de l'utilisateur. S'y brancher
     * ne conserverait pas un contexte : cela en fabriquerait un, et ferait
     * apparaitre un prefixe d'Organization dans des parcours qui n'en ont
     * exprime aucun. C'est la lecon mesuree de TASK-1601 et TASK-1602.
     *
     * On ne nomme par ailleurs que l'Organization ECRITE dans l'URL : si le
     * liage pointait ailleurs, on retombe sur la route globale plutot que de
     * fabriquer un lien vers un tenant que le visiteur n'a pas demande.
     *
     * DIFFERENCE ASSUMEE avec `aiOffersUrl()` ci-dessous, qui exclut
     * l'Organization par defaut : l'arbitrage MASTER de TASK-1604 demande
     * explicitement `/org/main/mentions-legales` et `/org/main/mycelium`.
     * `main` est donc borne comme les autres.
     *
     * Le contenu servi est le MEME : seules l'URL, la navigation et la charte
     * changent. Aucune page, aucune donnee n'est dupliquee.
     */
    function organizationScopedUrl(string $globalRoute, string $scopedRoute): string
    {
        $request = request();
        $organization = currentOrganization();

        if ($request->segment(1) === 'org'
            && $organization
            && $organization->slug === $request->segment(2)
            && Route::has($scopedRoute)) {
            return route($scopedRoute, ['organization' => $organization->slug]);
        }

        return route($globalRoute);
    }
}

if (! function_exists('showsOrganizationInternalNavigation')) {
    /**
     * TASK-1692 — la navigation interne d'une Organization ne s'affiche qu'a
     * ses membres.
     *
     * Le predicat vivait EN DOUBLE, ecrit a la main dans `partials/footer` et
     * absent de `mentions-legales`, qui se contentait de `auth()->check()`.
     * Une garde conditionnee a la seule AUTHENTIFICATION ne garde rien ici :
     * mesure avant correctif, un membre de A ouvrant
     * `/org/B/mentions-legales` recevait les 6 destinations internes de B
     * (`dashboard`, `loops`, `agenda`, `membres`, `blog`, `explorer`), toutes
     * prefixees par le slug de B. La page reste publique — c'est sa
     * NAVIGATION qui devient membre.
     *
     * LE DECLENCHEUR EST L'URL, comme pour `organizationScopedUrl()` : sans
     * prefixe `/org/{organization}`, aucune Organization n'est designee, donc
     * il n'y a pas de frontiere a tenir et la fonction rend `true`. Elle ne
     * dit rien de l'authentification : l'appelant la compose avec la sienne.
     */
    function showsOrganizationInternalNavigation(): bool
    {
        $organizationRouteParam = request()->route('organization');

        if (! (request()->routeIs('organization.*') && $organizationRouteParam)) {
            return true;
        }

        $organization = currentOrganization();
        $actor = request()->user();

        if (! $actor || ! $organization) {
            return false;
        }

        return $actor->organization_id === $organization->id || $actor->is_admin;
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
