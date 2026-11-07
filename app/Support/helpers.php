<?php

use App\Models\Organization;
use App\Services\TranslationOverrideService;
use App\Support\ScenarioManager\Persona\ScenarioPersonaAccess;
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
        // TASK-1694 bug AI — hors preface d'URL, l'Organization COURANTE n'est
        // pas celle qu'on croit.
        //
        // Signale par Cyril le 24/10 : la case restait visible sur
        // `/profile/edit` alors qu'OFSH avait la fonctionnalite decochee.
        // Mesure : `currentOrganization()` retombe sur l'Organization PAR
        // DEFAUT (`main`) des que l'URL ne porte pas de prefixe — et `main`,
        // elle, l'a activee. La page repondait donc pour une Organization dont
        // la personne ne regardait pas les pages.
        //
        // La regle est donc : s'il y a un tenant DANS L'URL, c'est lui qui
        // decide — c'est l'ecran d'une Organization, et c'est elle qui dit ce
        // qu'elle montre. Sinon, c'est l'Organization de la personne connectee :
        // sur une page sans tenant, elle ne voit que ses propres donnees.
        $route = request()?->route();

        $organization = $route?->parameter('organization')
            ? currentOrganization()
            : (auth()->user()?->organization ?? currentOrganization());

        return $organization?->availabilityEnabled() ?? true;
    }
}

if (! function_exists('billingIsCollected')) {
    /**
     * TASK-1762 point 15 — cette Organization recueille-t-elle une adresse
     * de facturation ?
     *
     * ## Pourquoi la question se pose
     *
     * Cyril, 05/11 : « si on ne propose pas d'abonnement, pas la peine de
     * parler de facturation, ça fait peur aux utilisateurs. Puis ainsi on
     * évite de recueillir des coordonnées pour rien. »
     *
     * La section « Informations privées / facturation » du profil — adresse,
     * complement, code postal — est donc suspendue a « Proposer les
     * abonnements ». C'est autant une question de minimisation des donnees
     * que de confiance : demander une adresse postale sans raison visible est
     * le genre de detail qui fait fermer un formulaire.
     *
     * ## La resolution est celle de `structureIsShown()`
     *
     * Et pour la meme raison : `currentOrganization()` retombe sur
     * l'Organization PAR DEFAUT quand l'URL ne porte pas de prefixe de tenant
     * (bug AI de T1694). S'il y a un tenant dans l'URL, c'est lui qui decide ;
     * sinon, c'est l'Organization de la personne connectee.
     *
     * Une ECRITURE passe l'Organization explicitement : elle se lie a celle de
     * la personne ecrite, jamais a ce que l'URL raconte.
     */
    function billingIsCollected(?\App\Models\Organization $organization = null): bool
    {
        if ($organization === null) {
            $route = request()?->route();

            $organization = $route?->parameter('organization')
                ? currentOrganization()
                : (auth()->user()?->organization ?? currentOrganization());
        }

        // `false` par defaut : on ne demande pas une adresse postale faute de
        // savoir a qui on a affaire.
        return (bool) ($organization?->subscriptions_enabled ?? false);
    }
}

if (! function_exists('structureIsShown')) {
    /**
     * TASK-1762 — cette Organization montre-t-elle le champ « Structure » ?
     *
     * ## Une seule definition, parce qu'il y a bientot sept surfaces
     *
     * Le drapeau gouverne l'edition du profil, sa validation, les cartes de
     * l'annuaire, la fiche publique, l'ecran SuperAdmin et deux recherches.
     * Ecrit sept fois, il finirait par diverger sur celle qu'on regarde le
     * moins.
     *
     * ## La resolution est celle d'`availabilityIsShown()`, et pour la meme raison
     *
     * `currentOrganization()` retombe sur l'Organization PAR DEFAUT quand l'URL
     * ne porte pas de prefixe de tenant — c'est le bug AI de T1694, signale par
     * Cyril le 24/10 : une case restait visible sur `/profile/edit` alors que
     * l'Organization concernee l'avait decochee.
     *
     * S'il y a un tenant DANS L'URL, c'est lui qui decide — c'est son ecran.
     * Sinon, c'est l'Organization de la personne connectee.
     *
     * ## `$organization` explicite pour les ECRITURES
     *
     * Un appelant qui sait de quelle Organization il parle la passe. Une garde
     * d'ecriture doit se lier a l'Organization de la personne ECRITE, jamais a
     * ce que l'URL raconte.
     */
    function structureIsShown(?\App\Models\Organization $organization = null): bool
    {
        if ($organization === null) {
            $route = request()?->route();

            $organization = $route?->parameter('organization')
                ? currentOrganization()
                : (auth()->user()?->organization ?? currentOrganization());
        }

        // `false` et non `true` par defaut : le champ n'existe que la ou on l'a
        // VOULU. Sans Organization resolue, on ne montre rien.
        return (bool) ($organization?->structure_enabled ?? false);
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

if (! function_exists('accueilDuCompteAuthentifie')) {
    /**
     * TASK-1782 — ou doit atterrir le compte authentifie quand il demande la
     * RACINE du site, ou le retour depuis son tableau de bord.
     *
     * ## Une seule implementation, pour tous les tenants
     *
     * Aucun slug n'apparait ici, et aucun n'y entrera : la decision se prend
     * sur les drapeaux de l'Organization du COMPTE (`is_active`, `is_default`),
     * jamais sur son nom. Une Organization creee demain est donc traitee comme
     * OFSH l'est aujourd'hui, sans toucher a cette fonction.
     *
     * ## La destination est la HOMEPAGE, pas les Boucles
     *
     * Regle produit arbitree par Cyril : pour toute Organization scopee
     * active, l'accueil demande est sa VRAIE homepage — `/org/{slug}` — y
     * compris quand `loops_enabled` vaut vrai.
     *
     * C'est pourquoi cette fonction ne delegue PAS a `canonicalHome()`, qui
     * rendrait `/org/{slug}/loops` dans ce cas. `canonicalHome()` reste
     * l'autorite de destination des AUTRES parcours — connexion, bascule de
     * compte, changement de langue — et son comportement historique n'est pas
     * touche. Deux questions distinctes : « ou va-t-on apres une bascule »
     * n'est pas « quel est l'accueil de cette Organization ».
     *
     * ## Les quatre refus, et pourquoi chacun rend `null`
     *
     * `null` veut dire « ne redirige pas, sers ce que tu servais » — jamais
     * « erreur ». Les quatre cas :
     *
     *  1. **Visiteur anonyme** — la homepage publique est le produit. Elle ne
     *     doit jamais devenir inatteignable.
     *
     *  2. **Administrateur global** — la racine est SOUVERAINE depuis
     *     TASK-1628 : `/admin/homepage` decide de ce qu'elle sert au public.
     *     Rediriger le superadmin lui retirerait le seul moyen de VOIR le
     *     choix qu'il vient de faire. Son pouvoir sur la racine serait intact
     *     et invisible, ce qui revient a le casser.
     *
     *  3. **Compte sans Organization, ou Organization inactive** — c'est la
     *     verification de statut. Envoyer quelqu'un dans une Organization
     *     desactivee le menerait a une porte fermee, moins utile que
     *     l'accueil public. Meme garde que `getLoginRedirectTarget()`.
     *
     *  4. **Organization par defaut** — c'est elle que la racine sert DEJA,
     *     et son comportement est preserve tel quel. C'est le drapeau
     *     `is_default` qui le dit, jamais le slug « main ».
     *
     *  5. **Mode persona actif** — et c'est une garde de SECURITE, mesuree.
     *     Le bandeau du mode persona et son bouton de sortie vivent dans
     *     `layouts/app.blade.php` et `layouts/org-admin.blade.php`. Or trois
     *     des cinq gabarits de landing d'Organization — `hero-v2`,
     *     `shell-first`, `artscilab-hero` — sont des documents HTML COMPLETS
     *     ecrits en propre : ils n'etendent aucun de ces layouts, donc ils ne
     *     portent NI bandeau NI sortie.
     *
     *     Rediriger la racine vers la landing d'une sandbox pourrait donc
     *     deposer un operateur en mode persona sur une page qui ne dit pas
     *     qu'il y est, et dont il ne peut pas sortir. L'invariant de TASK-1654
     *     est precisement celui-la, et le layout `org-admin` porte la trace
     *     d'un defaut de cette meme famille, deja corrige une fois.
     *
     *     Le refus est donc fail-closed, dans l'esprit de
     *     `EnsureScenarioPersonaContextIsValid` : « quand une condition tombe,
     *     on ne continue prudemment pas ». `actif()` coute une lecture de
     *     session et aucune requete SQL — il est fait pour etre appele
     *     partout.
     *
     *     NB : l'absence de bandeau sur ces trois gabarits PREEXISTE a cette
     *     TASK — un persona qui ouvre `/org/{slug}` par un lien l'y rencontre
     *     deja. Ce refus ne la corrige pas, il refuse de la rendre plus facile
     *     a atteindre. Le signalement est remonte a MASTER a part.
     *
     * @return string|null chemin relatif, ou `null` s'il ne faut pas rediriger
     */
    function accueilDuCompteAuthentifie(): ?string
    {
        $compte = auth()->user();

        if ($compte === null) {
            return null;
        }

        // Le superadmin garde la racine telle qu'il l'a choisie pour le public.
        if ($compte->is_admin) {
            return null;
        }

        $organization = $compte->organization;

        if ($organization === null || ! $organization->is_active) {
            return null;
        }

        // L'Organization par defaut est ce que la racine sert deja.
        if ($organization->is_default) {
            return null;
        }

        // Garde de securite TASK-1654 : ne jamais deposer un persona sur une
        // landing qui ne porte ni bandeau ni sortie. Detail dans le docbloc.
        if (ScenarioPersonaAccess::actif()) {
            return null;
        }

        // La homepage de l'Organization, et non ses Boucles. Le slug vient du
        // COMPTE authentifie, jamais de la route ni d'un cookie.
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
        $cachePath = bp_themes_cache_path();

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

if (! function_exists('bp_themes_cache_path')) {
    /**
     * TASK-1749 — le cache de themes a un fichier PAR ENVIRONNEMENT.
     *
     * ## Le defaut corrige, et il etait silencieux
     *
     * `Theme::regenerateCache()` ecrivait toujours
     * `storage/app/bouclepro-themes.php`. Or `storage_path()` n'est PAS
     * redirige en environnement de test, et la migration
     * `2026_10_25_230000_write_the_ofsh_palette_into_its_theme_row` appelle
     * cette methode dans son `up()`.
     *
     * Consequence mesuree le 04/11 : chaque `RefreshDatabase` republiait le
     * cache DEPUIS LA BASE DE TEST, par-dessus celui du poste de travail. Les
     * themes presents en base de dev mais absents des seeders — `lp` et `ut` —
     * disparaissaient. Un simple `bin/test` faisait donc basculer la porte de
     * LaunchPals sur la couleur de `zen`, sans que rien ne le signale.
     *
     * ## Seul le TEST devie, et c'est delibere
     *
     * Suffixer par environnement aurait fait perdre son cache a `local`, a
     * `ai-validation` et a tout autre : le fichier existant s'appelle
     * `bouclepro-themes.php`, et ils seraient tous retombes sur la
     * configuration — donc sur des themes sans les personnalisations de leur
     * base — jusqu'a une regeneration manuelle.
     *
     * Le defaut mesure ne vient que du banc de test. C'est donc LUI qu'on
     * devie, et rien d'autre : aucun environnement reel ne change de fichier.
     *
     * Lecteur et ecrivain passent par cette fonction — c'etait la condition
     * pour qu'ils ne divergent pas.
     */
    function bp_themes_cache_path(): string
    {
        return app()->environment('testing')
            ? storage_path('app/bouclepro-themes.testing.php')
            : storage_path('app/bouclepro-themes.php');
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
