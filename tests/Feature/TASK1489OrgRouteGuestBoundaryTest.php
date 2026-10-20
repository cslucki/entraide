<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1489 — la garde d'inventaire : une route Organization ne devient pas
 * joignable sans session par accident.
 *
 * ## Pourquoi ce fichier existe
 *
 * Deux P0 de privacy en vingt-quatre heures, tous deux sortis du MEME endroit :
 * le tableau de bord (TASK-1483) et la fiche Service / Demande + la recherche
 * (TASK-1488). Aucune garde structurelle n'a signale l'oubli — c'est un audit
 * humain qui les a trouves, une fois en production potentielle.
 *
 * TASK-1488 est l'illustration exacte du defaut que ce fichier ferme : les deux
 * routes vivaient sous un commentaire qui affirmait « Public organization-scoped
 * detail routes used by Explorer », alors que l'Explorer etait member-only
 * depuis TASK-1479 et que la fiche de profil du MEME bloc portait deja la garde.
 * Un commentaire n'est pas une autorite, et rien ne le confrontait au reel.
 *
 * ## Ce que cette garde mesure, et ce qu'elle ne mesure PAS
 *
 * Mesure faite sur `Route::getRoutes()` au moment d'ecrire :
 *
 * | Famille | Nombre |
 * |---|---|
 * | `OrgAdminMiddleware` | 127 |
 * | authentifiees, frontiere deleguee (policy / assertion controleur) | 188 |
 * | `EnsureOrganizationMember` | 11 |
 * | **aucune authentification** | **16** |
 *
 * Cette garde ne police QUE la derniere colonne. C'est un choix, pas un oubli :
 *
 * - les 188 ne sont PAS 188 bugs. Elles posent une autre question — le
 *   cross-tenant entre gens connectes — que `EnsureOrganizationMember` ne
 *   resout pas seule et qu'une liste blanche de 188 entrees rendrait
 *   ingerable, donc fausse au premier oubli ;
 * - les deux P0 recents sont sortis de la colonne « aucune authentification ».
 *   C'est la que la frontiere de vie privee se joue, et c'est la seule liste
 *   assez petite pour rester VRAIE.
 *
 * Une route publique reste parfaitement legitime — l'accueil d'une Organization
 * publique, le blog, les Ateliers, le Shell Welcome. Ce qu'elle ne peut plus
 * etre, c'est publique SANS QUE PERSONNE NE L'AIT DECIDE.
 */
class TASK1489OrgRouteGuestBoundaryTest extends TestCase
{
    /**
     * Les middlewares qui VALENT frontiere declaree. On compare des classes,
     * jamais des sous-chaines : voir `requiresSession()`.
     *
     * @var array<int, class-string>
     */
    private const SESSION_BARRIERS = [
        \Illuminate\Auth\Middleware\Authenticate::class,
        \Illuminate\Auth\Middleware\RedirectIfAuthenticated::class,
        \App\Http\Middleware\EnsureOrganizationMember::class,
        \App\Http\Middleware\OrgAdminMiddleware::class,
    ];

    /**
     * Les surfaces Organization joignables SANS session, et la decision qui
     * les autorise. Ajouter une ligne ici est un geste deliberе : c'est
     * l'endroit ou la publicite d'une surface est ecrite.
     *
     * @var array<string, string>
     */
    private const PUBLIC_BY_DECISION = [
        // Vitrine d'une Organization PUBLIQUE. Mesure TASK-1479 : une
        // Organization privee ne rend pas de landing du tout (302).
        //
        // TASK-1692 l'a EXPLICITEMENT exceptee de la garde du groupe
        // (`withoutMiddleware(['auth', 'organization.member'])`) : sa publicite
        // est donc ecrite a deux endroits qui se confirment, la route et cette
        // ligne. Mesure MICRO-11.2A : HTTP anonyme 200.
        'organization.home' => 'Accueil public d\'une Organization publique.',

        // TASK-1602 — les mentions legales servies DANS le contexte d'une
        // Organization. Publiques par nature : c'est la MEME vue que la route
        // globale `/mentions-legales`, deja publique, et son contenu est unique
        // (aucune duplication par tenant). Seuls la navigation et la charte
        // changent. Rien d'interne n'y transite.
        //
        // Meme exception explicite que `organization.home` sous TASK-1692, et
        // meme mesure : HTTP anonyme 200. TASK-1692 / MICRO-07 a par ailleurs
        // rendu sa NAVIGATION membre-only — la page reste publique, son rail
        // ne l'est plus.
        'organization.mentions-legales' => 'Mentions legales, contenu global servi dans le contexte de l\'Organization.',

        // ─── TASK-1692 / MICRO-11.2B — DIX-HUIT ENTREES RETIREES ────────────
        //
        // Ces dix-huit surfaces etaient declarees publiques ici et ne le sont
        // plus : TASK-1692 a pose `auth` + `organization.member` sur le groupe
        // `/org/{organization}` entier et n'en a excepte que les deux lignes
        // ci-dessus et le groupe `guest`. Mesure : elles rendent 302 vers le
        // login a un visiteur anonyme.
        //
        //   about, constitution, subscriptions, mycelium, open-source,
        //   blog.index, blog.show, blog.category, blog.tag,
        //   workshop.show, workshop.session.interest,
        //   workshop.session.interest.withdraw, shell.show, shell.message,
        //   boucles.index, flowchart, bug-reports.index,
        //   agent-ia.profile.chat
        //
        // IL FAUT LE DIRE NET : aucune n'a recu de decision INDIVIDUELLE. Elles
        // ont ete fermees collateralement, par un middleware de groupe. C'est
        // l'exact symetrique du defaut que ce fichier existe pour empecher —
        // « publique sans que personne ne l'ait decide » devient ici « fermee
        // sans que personne ne l'ait decide ». Le GO MICRO-11.1 a tranche de ne
        // pas les rouvrir, et le GO MICRO-11.2B de retirer leurs lignes : cette
        // decision EST l'autorite qui leur manquait, et c'est pourquoi elle est
        // ecrite ici plutot que simplement executee.
        //
        // DEUX ESCALADES PARTENT AVEC ELLES, ET N'ONT JAMAIS ETE TRANCHEES.
        // Elles ne sont pas resolues : elles sont rendues SANS OBJET par la
        // fermeture. Si l'une de ces surfaces etait rouverte un jour, elles
        // redeviendraient ouvertes le meme jour, et ce bloc est leur seule
        // trace dans ce fichier :
        //
        //   * BLOG (`blog.index`, `blog.show`, `blog.category`, `blog.tag`) —
        //     mesure TASK-1489 sur une Organization `is_public = false` :
        //     l'article publie etait servi 200, avec son titre, a un membre
        //     d'une AUTRE Organization ET a un anonyme complet.
        //     `BlogController@index` ne filtre que sur `organization_id` — ni
        //     appartenance, ni publicite. L'article porte un opt-in d'auteur
        //     (`status = published`), ce qui en faisait un arbitrage PRODUIT et
        //     non un defaut evident.
        //     -> SECURITY_PRODUCT_DECISION_REQUIRED_BEFORE_STABILIZATION.
        //
        //   * AGENT IA (`agent-ia.profile.chat`) — asymetrie escaladee par
        //     TASK-1479 et jamais tranchee : la fiche de profil HUMAINE est
        //     fermee, l'agent IA du meme membre restait joignable par URL
        //     directe, sans `Authenticate`. Il porte, lui, un opt-in explicite
        //     (STATUS_PUBLISHED) que le profil humain n'avait pas.
        //     -> UNCLASSIFIED (TASK-1479).
        //
        // Trois contrats produits sont par ailleurs CONTREDITS par cette
        // fermeture, et c'est a MASTER de les reecrire ou de les assumer :
        // le mandat TASK-1608 (« Logigramme public PAR MANDAT : elle explique
        // le produit a un visiteur qui n'a pas de compte »), le contrat
        // TASK-1604 (le Mycelium scope « sert le MEME contenu que le global »,
        // alors que `/mycelium` global rend toujours 200 en anonyme et le
        // scope 302), et TASK-1613 pour `open-source`, de meme forme.
        //
        // Les SEPT routes d'authentification d'une Organization (login,
        // register, forgot/reset password…) ne sont PAS concernees et restent
        // joignables : elles portent `guest` / `RedirectIfAuthenticated`, donc
        // une frontiere DECLAREE, et le predicat les compte a ce titre comme
        // « quelqu'un a decide ». Elles n'ont jamais eu a figurer ici.
    ];

    /**
     * Le coeur de la garde : toute route Organization joignable sans session
     * doit etre DECLAREE. Une nouvelle route qui oublie sa frontiere rougit
     * ici, avec son nom et le geste a faire.
     */
    public function test_every_guest_reachable_organization_route_is_declared(): void
    {
        $reachable = $this->guestReachableRoutes();

        // TASK-1692 / MICRO-11.2A — la garde doit d'abord prouver qu'elle
        // REGARDE quelque chose. Elle a passe, sans rien mesurer, tout le temps
        // ou le predicat voyait 354 routes sur 354 comme authentifiees : la
        // boucle ci-dessous tournait a vide et l'assertion finale comparait
        // deux tableaux vides. Un test qui boucle sur zero element est vert
        // pour la pire des raisons.
        $this->assertNotSame([], $this->organizationRoutes(), 'Aucune route /org/{organization} inspectee : la garde ne mesure rien.');
        $this->assertNotSame([], $reachable, 'Aucune route joignable sans session : le predicat est casse, la garde est VACUE.');

        $undeclared = array_diff_key($reachable, self::PUBLIC_BY_DECISION);

        $this->assertSame([], $undeclared, sprintf(
            "Ces routes Organization sont joignables SANS AUCUNE SESSION et personne ne l'a decide :\n%s\n\n".
            "Deux choses seulement sont possibles, et le choix n'appartient pas a ce test :\n".
            "  1. la surface est INTERNE -> lui poser ['auth', 'organization.member'],\n".
            "     comme TASK-1479 et TASK-1488 l'ont fait ;\n".
            "  2. la surface est VRAIMENT publique -> l'inscrire dans PUBLIC_BY_DECISION\n".
            "     avec la raison, qui devient l'autorite ecrite de sa publicite.\n\n".
            "Un commentaire au-dessus de la route ne suffit pas : c'est precisement\n".
            'ce qui a laisse passer TASK-1488.',
            implode("\n", array_map(
                static fn ($uri, $n) => "  - $n   ($uri)",
                $undeclared,
                array_keys($undeclared)
            ))
        ));
    }

    /**
     * L'autre moitie du contrat, et elle compte autant : la liste blanche ne
     * doit pas survivre a la fermeture d'une route. Sans ce test, une entree
     * perimee resterait a decrire comme « publique » une surface fermee depuis
     * — et la prochaine lecture croirait une chose fausse.
     */
    public function test_the_allowlist_does_not_keep_stale_entries(): void
    {
        $stale = array_diff(array_keys(self::PUBLIC_BY_DECISION), array_keys($this->guestReachableRoutes()));

        $this->assertSame([], array_values($stale), sprintf(
            "PUBLIC_BY_DECISION declare publiques des routes qui ne le sont plus (ou n'existent plus) : %s.\n".
            'Retirer ces lignes — une autorite perimee est pire qu\'absente.',
            implode(', ', $stale)
        ));
    }

    /**
     * La preuve que la garde MORD. Une route Organization fictive, ajoutee sans
     * frontiere, doit etre vue — sinon les deux tests ci-dessus seraient verts
     * pour la seule raison qu'ils ne regardent rien.
     *
     * On mesure le predicat sur une vraie `Route` enregistree, pas sur une
     * chaine : c'est la difference entre mesurer le rendu et relire le code.
     */
    public function test_a_new_unguarded_organization_route_is_detected(): void
    {
        // TASK-1692 / MICRO-11.2A — la route fictive vit desormais dans la pile
        // REELLE du groupe, et s'en excepte comme une vraie surface publique le
        // ferait.
        //
        // Avant, elle etait posee dans un groupe A PART, avec `['web',
        // 'organization']` recopies a la main. Ce raccourci a coute cher : le
        // jour ou TASK-1692 a pose `auth` sur le groupe reel et desarme la
        // garde, ce test est reste VERT — il mesurait une configuration qui
        // n'existait plus nulle part. Un test de morsure doit mordre sur le
        // montage reel, sinon il ne prouve que sa propre coherence.
        Route::prefix('/org/{organization}')
            ->middleware($this->realOrganizationGroupMiddleware())
            ->name('organization.')
            ->group(function () {
                Route::get('/task1489-fictive-surface', fn () => 'x')
                    ->withoutMiddleware(['auth', 'organization.member'])
                    ->name('task1489.fictive');
            });

        Route::getRoutes()->refreshNameLookups();

        $fictive = collect($this->organizationRoutes())
            ->first(fn (RoutingRoute $r) => $r->getName() === 'organization.task1489.fictive');

        $this->assertNotNull($fictive, 'La route fictive n\'a pas ete enregistree — le test ne mesurerait rien.');
        $this->assertFalse(
            $this->requiresSession($fictive),
            'La route fictive est vue comme authentifiee : le predicat de la garde est casse.'
        );
        $this->assertArrayNotHasKey(
            'organization.task1489.fictive',
            self::PUBLIC_BY_DECISION,
            'La route fictive ne doit pas etre declaree — c\'est ce qui la rend detectable.'
        );

        // Et la morsure elle-meme : la garde la voit, par le MEME chemin de
        // code qu'en production de test — `guestReachableRoutes()`.
        $this->assertArrayHasKey(
            'organization.task1489.fictive',
            $this->guestReachableRoutes(),
            'La surface fictive publique n\'apparait pas dans l\'inventaire : la garde ne la signalerait pas.'
        );
    }

    /**
     * TASK-1692 / MICRO-11.2A — le predicat VOIT `withoutMiddleware()`.
     *
     * C'est la non-regression du defaut qui a desarme la garde. Elle nomme les
     * deux routes concernees plutot qu'un compte, pour qu'un echec dise
     * LAQUELLE a change de statut.
     */
    public function test_the_predicate_sees_routes_excepted_from_the_group_guard(): void
    {
        $reachable = $this->guestReachableRoutes();

        foreach (['organization.home', 'organization.mentions-legales'] as $name) {
            $route = collect($this->organizationRoutes())
                ->first(fn (RoutingRoute $r) => $r->getName() === $name);

            $this->assertNotNull($route, "$name a disparu du groupe Organization.");

            // La route PORTE bien la garde du groupe — c'est son exception qui
            // la rend publique. Si cette ligne tombait, le montage aurait
            // change et la non-regression ne mesurerait plus le bon defaut.
            $this->assertContains('auth', array_map('strval', $route->gatherMiddleware()), "$name n'herite plus de `auth` : ce test ne mesure plus l'exception.");
            $this->assertContains('auth', array_map('strval', $route->excludedMiddleware()), "$name n'excepte plus `auth` : le montage a change.");

            $this->assertFalse($this->requiresSession($route), "$name est exceptee de la garde du groupe et doit etre vue PUBLIQUE.");
            $this->assertArrayHasKey($name, $reachable, "$name doit figurer dans l'inventaire des surfaces sans session.");
        }
    }

    /**
     * Le contre-exemple du meme defaut : une route qui herite la garde du
     * groupe SANS s'en excepter ne doit jamais etre vue publique. Sinon la
     * correction du predicat aurait pu ouvrir la vanne dans l'autre sens.
     */
    public function test_a_route_inheriting_the_group_guard_is_never_seen_public(): void
    {
        $reachable = $this->guestReachableRoutes();

        foreach (['organization.mycelium', 'organization.dashboard', 'organization.members.index'] as $name) {
            $route = collect($this->organizationRoutes())
                ->first(fn (RoutingRoute $r) => $r->getName() === $name);

            $this->assertNotNull($route, "$name a disparu du groupe Organization.");
            $this->assertTrue($this->requiresSession($route), "$name porte la garde du groupe et ne doit JAMAIS etre vue publique.");
            $this->assertArrayNotHasKey($name, $reachable, "$name ne doit pas figurer dans l'inventaire des surfaces sans session.");
        }
    }

    /**
     * TASK-1692 / MICRO-11.2B — les routes d'authentification ne sont PAS des
     * surfaces publiques, et ne doivent pas le devenir par confusion.
     *
     * Elles sont joignables sans etre connecte — c'est leur raison d'etre — mais
     * elles portent `guest` / `RedirectIfAuthenticated` : une frontiere
     * DECLAREE. Le predicat les compte donc dans « quelqu'un a decide », et
     * elles n'ont jamais eu a figurer dans `PUBLIC_BY_DECISION`.
     *
     * Ce test tient les deux bouts : si quelqu'un retirait `guest` a l'une
     * d'elles, elle basculerait en surface publique NON DECLAREE et la garde
     * anti-P0 la signalerait — c'est ce qu'on veut. Et tant que `guest` est la,
     * aucune ne doit etre inscrite a l'inventaire, sous peine de rendre cet
     * inventaire faux dans l'autre sens.
     */
    public function test_the_organization_authentication_routes_are_declared_boundaries_not_public_surfaces(): void
    {
        $reachable = $this->guestReachableRoutes();
        $seen = 0;

        foreach ($this->organizationRoutes() as $route) {
            $isGuestRoute = false;

            foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
                if (is_string($middleware)
                    && is_a(explode(':', $middleware, 2)[0], \Illuminate\Auth\Middleware\RedirectIfAuthenticated::class, true)) {
                    $isGuestRoute = true;
                    break;
                }
            }

            if (! $isGuestRoute) {
                continue;
            }

            $seen++;
            $name = $route->getName() ?? $route->uri();

            $this->assertTrue($this->requiresSession($route), "$name porte `guest` : c'est une frontiere DECLAREE, pas une surface publique.");
            $this->assertArrayNotHasKey($name, $reachable, "$name ne doit pas figurer dans l'inventaire des surfaces sans session.");
            $this->assertArrayNotHasKey($name, self::PUBLIC_BY_DECISION, "$name n'a pas a etre declaree publique : sa frontiere est `guest`.");
        }

        // TASK-1692 / MICRO-11.2A : login, register, forgot-password et
        // reset-password, en GET et en POST. Les compter evite que ce test
        // devienne vert parce qu'il ne trouve plus aucune route `guest`.
        $this->assertSame(8, $seen, 'Les huit endpoints d\'authentification d\'une Organization doivent porter `guest`.');
    }

    /**
     * TASK-1692 / MICRO-11.2A — l'inventaire n'est jamais vide EN SILENCE.
     *
     * C'est le test qui aurait sonne pendant que les deux gardes passaient a
     * vide. Il mesure les trois nombres qui disent si la garde regarde encore :
     * les routes inspectees, celles vues authentifiees, celles vues publiques.
     */
    public function test_the_inventory_is_never_silently_empty(): void
    {
        $routes = $this->organizationRoutes();
        $reachable = $this->guestReachableRoutes();

        $this->assertGreaterThan(100, count($routes), 'Le groupe Organization compte quelques centaines de routes : en trouver moins signale que le filtre d\'uri ne mord plus.');
        $this->assertGreaterThanOrEqual(1, count($reachable), 'Aucune surface sans session : la garde serait VACUE, exactement comme avant MICRO-11.2A.');
        $this->assertLessThan(count($routes), count($reachable), 'TOUTES les routes vues publiques : le predicat ne distingue plus rien.');
    }

    /** Le contre-exemple : une route qui PORTE la garde ne doit pas etre signalee. */
    public function test_a_guarded_organization_route_is_not_flagged(): void
    {
        $guarded = collect($this->organizationRoutes())
            ->first(fn (RoutingRoute $r) => $r->getName() === 'organization.members.index');

        $this->assertNotNull($guarded, 'organization.members.index a disparu — reference de TASK-1479.');
        $this->assertTrue(
            $this->requiresSession($guarded),
            'organization.members.index doit rester authentifiee (TASK-1479).'
        );
    }

    /** @return array<RoutingRoute> */
    private function organizationRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route) => str_starts_with($route->uri(), 'org/{organization}')
        ));
    }

    /**
     * « Cette route porte-t-elle une frontiere DECLAREE ? »
     *
     * `guest` (`RedirectIfAuthenticated`) compte comme une frontiere declaree,
     * et la distinction est le coeur de cette garde : « reserve a qui n'est PAS
     * connecte » est une decision ECRITE dans la route — c'est le cas des sept
     * routes d'authentification d'une Organization (login, inscription, mot de
     * passe oublie). L'absence totale de marqueur, elle, n'est la trace de
     * rien : c'est exactement l'etat dans lequel se trouvaient les fiches
     * Service et Demande avant TASK-1488.
     *
     * La garde separe donc « quelqu'un a decide » de « personne n'a decide ».
     *
     * ## TASK-1692 / MICRO-11.2A — ce predicat avait DESARME la garde
     *
     * Il interrogeait `gatherMiddleware()`, qui rend bien la chaine des groupes
     * — mais qui **ne soustrait PAS** `withoutMiddleware()` : les exclusions
     * vivent dans `excludedMiddleware()` et ne sont appliquees qu'au dispatch,
     * par le Router. Des que TASK-1692 a pose `auth` + `organization.member`
     * sur le groupe `/org/{organization}` entier, en n'exceptant que `home`,
     * `mentions-legales` et le groupe `guest`, le predicat a vu **354 routes
     * sur 354** comme authentifiees.
     *
     * Degat visible : `organization.home` et `organization.mentions-legales`
     * etaient signalees perimees alors qu'elles rendent 200 a un anonyme.
     * Degat REEL, et silencieux :
     * `test_every_guest_reachable_organization_route_is_declared` ne parcourait
     * plus AUCUNE route. La garde ecrite apres deux P0 de vie privee passait
     * parce qu'elle ne regardait plus rien.
     *
     * Deux corrections, et la seconde compte autant que la premiere :
     *
     *  1. on demande les middlewares EFFECTIFS a Laravel lui-meme —
     *     `Router::gatherRouteMiddleware()` resout les alias ET applique les
     *     exclusions. Reimplementer cette soustraction a la main, c'est se
     *     donner une seconde occasion de diverger du framework ;
     *  2. on compare des CLASSES, plus des sous-chaines. `str_contains(…,
     *     'Authenticate')` est vrai pour n'importe quel middleware dont le nom
     *     contient ce mot — `EnsureAuthenticatedSomething`, un jour. On coupe
     *     donc le parametre eventuel (`EnsureOrganizationMember:explain`) et on
     *     verifie l'heritage avec `is_a()`, ce qui prend aussi les sous-classes.
     */
    private function requiresSession(RoutingRoute $route): bool
    {
        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // `EnsureOrganizationMember:explain` -> `EnsureOrganizationMember`.
            // Mesure TASK-1692 : exclure `organization.member` du groupe ne
            // retire PAS la variante parametree posee sur la route, et c'est
            // bien ce que rend `gatherRouteMiddleware()`.
            $class = explode(':', $middleware, 2)[0];

            foreach (self::SESSION_BARRIERS as $barrier) {
                if (is_a($class, $barrier, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Les noms des routes Organization joignables SANS session, calcules UNE
     * fois et partages par les deux gardes et par le test de morsure. Avant,
     * chacun refaisait la boucle : le test de morsure pouvait donc rester vert
     * sur une copie pendant que la garde reelle, elle, ne mesurait rien.
     *
     * @return array<string, string> nom de route => uri
     */
    private function guestReachableRoutes(): array
    {
        $reachable = [];

        foreach ($this->organizationRoutes() as $route) {
            if ($this->requiresSession($route)) {
                continue;
            }

            $reachable[$route->getName() ?? $route->uri()] = $route->uri();
        }

        return $reachable;
    }

    /**
     * La pile de middlewares que le groupe `/org/{organization}` impose
     * REELLEMENT, lue sur les routes elles-memes : c'est ce que toutes
     * partagent. On ne la recopie pas en dur — une constante recopiee se perime
     * en silence le jour ou le groupe change, et le test de morsure mesurerait
     * alors une configuration qui n'existe plus.
     *
     * @return array<int, string>
     */
    private function realOrganizationGroupMiddleware(): array
    {
        $common = null;

        foreach ($this->organizationRoutes() as $route) {
            $middleware = array_map('strval', $route->gatherMiddleware());

            $common = $common === null
                ? $middleware
                : array_values(array_intersect($common, $middleware));
        }

        return $common ?? [];
    }
}
