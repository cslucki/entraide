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
     * Les surfaces Organization joignables SANS session, et la decision qui
     * les autorise. Ajouter une ligne ici est un geste deliberе : c'est
     * l'endroit ou la publicite d'une surface est ecrite.
     *
     * @var array<string, string>
     */
    private const PUBLIC_BY_DECISION = [
        // Vitrine d'une Organization PUBLIQUE. Mesure TASK-1479 : une
        // Organization privee ne rend pas de landing du tout (302).
        'organization.home' => 'Accueil public d\'une Organization publique.',
        'organization.about' => 'Page de presentation publique.',
        'organization.constitution' => 'Texte fondateur public.',
        'organization.subscriptions' => 'Grille d\'abonnements, surface commerciale.',

        // Blog public. ATTENTION a ce que T123 a reellement decide : cet audit
        // (2026-05-23) a durci le TENANT SCOPE — quels articles apparaissent —
        // et ne dit pas un mot de `is_public`, des Organizations privees, ni
        // des lecteurs anonymes. Verifie ligne a ligne avant d'ecrire ceci.
        //
        // Mesure TASK-1489 sur `test20260822` (`is_public = false`), article
        // reellement publie : servi 200 avec son titre a un membre d'une AUTRE
        // Organization ET a un anonyme complet. `BlogController@index` ne filtre
        // que sur `organization_id` — ni appartenance, ni publicite.
        //
        // C'est la meme question que TASK-1479 et TASK-1488 ont tranchee
        // ailleurs — sauf qu'ici l'article porte un opt-in EXPLICITE de son
        // auteur (`status = published`), ce que ni l'annuaire ni la fiche
        // Service n'avaient. Cette difference est reelle : elle fait du blog un
        // arbitrage PRODUIT, pas un defaut evident, et c'est pourquoi il est
        // escalade a MASTER plutot que decide ici.
        // -> SECURITY_PRODUCT_DECISION_REQUIRED_BEFORE_STABILIZATION.
        'organization.blog.index' => 'Blog public — ESCALADE : lisible par un anonyme sur une Organization privee (mesure TASK-1489).',
        'organization.blog.show' => 'Article public — ESCALADE : opt-in auteur `published`, mais lu par un anonyme sur une Organization privee.',
        'organization.blog.category' => 'Liste publique par categorie — meme escalade que blog.index.',
        'organization.blog.tag' => 'Liste publique par tag — meme escalade que blog.index.',

        // Ateliers publics (TASK-1450 -> TASK-1461) : la vitrine ET le premier
        // geste d'interet, qui doit rester possible avant de creer un compte.
        'organization.workshop.show' => 'Atelier public (TASK-1461).',
        'organization.workshop.session.interest' => 'Premier geste d\'interet, avant compte (TASK-1461).',
        'organization.workshop.session.interest.withdraw' => 'Retrait du meme geste (TASK-1461).',

        // Shell Welcome : surface publique par construction (TASK-1442), bornee
        // par son propre fail-closed economique (GuestShellGate).
        'organization.shell.show' => 'Shell Welcome public (TASK-1442).',
        'organization.shell.message' => 'Premier tour du Shell Welcome (TASK-1442).',

        // Vitrine des Boucles. MESUREE, et pas seulement joignable : sur
        // `org/main`, qui compte 19 Boucles reelles, la page rendue a un
        // anonyme est une presentation avec un appel a se connecter — aucun
        // nom de Boucle, aucun membre. C'est une surface marketing, pas la
        // liste. Un 200 ne disait pas cela ; il a fallu lire le rendu.
        'organization.boucles.index' => 'Vitrine publique des Boucles — rendu mesure : presentation + CTA connexion, jamais la liste.',

        // ─── TOLEREES, explicitement PAS validees ───

        // DEFAUT LATENT, signale a MASTER. La table `bug_reports` est VIDE
        // aujourd'hui, et c'est la SEULE raison pour laquelle cette page ne
        // fuit rien : le controleur filtre sur `whereIn('status', ['pending',
        // 'fixed'])` borne au tenant, sans jamais demander qui regarde. Des
        // qu'un rapport existera, il sera servi a un anonyme — y compris sur
        // une Organization privee. C'est la forme EXACTE du defaut de
        // TASK-1488, avant que la donnee n'arrive.
        //
        // Le piege que cette ligne documente : « Aucun bug public pour le
        // moment » se lit comme une garde de visibilite. Ce n'en est pas une.
        // C'est une table vide, et une table vide n'est pas une frontiere.
        'organization.bug-reports.index' => 'DEFAUT LATENT (TASK-1489) — ne fuit rien parce que la table est VIDE, pas parce qu\'une garde existe.',

        // Asymetrie ESCALADEE par TASK-1479 et jamais tranchee : la fiche de
        // profil humaine est fermee, l'agent IA du meme membre reste joignable
        // par URL directe. Il porte, lui, un opt-in explicite (STATUS_PUBLISHED)
        // que le profil humain n'avait pas — c'est ce qui rend l'arbitrage non
        // evident, et c'est pourquoi il revient a MASTER et pas a ce fichier.
        'organization.agent-ia.profile.chat' => 'UNCLASSIFIED (TASK-1479, escaladee) — opt-in STATUS_PUBLISHED, sans Authenticate.',
    ];

    /**
     * Le coeur de la garde : toute route Organization joignable sans session
     * doit etre DECLAREE. Une nouvelle route qui oublie sa frontiere rougit
     * ici, avec son nom et le geste a faire.
     */
    public function test_every_guest_reachable_organization_route_is_declared(): void
    {
        $undeclared = [];

        foreach ($this->organizationRoutes() as $route) {
            if ($this->requiresSession($route)) {
                continue;
            }

            $name = $route->getName() ?? $route->uri();

            if (! array_key_exists($name, self::PUBLIC_BY_DECISION)) {
                $undeclared[$name] = $route->uri();
            }
        }

        $this->assertSame([], $undeclared, sprintf(
            "Ces routes Organization sont joignables SANS AUCUNE SESSION et personne ne l'a decide :\n%s\n\n".
            "Deux choses seulement sont possibles, et le choix n'appartient pas a ce test :\n".
            "  1. la surface est INTERNE -> lui poser ['auth', 'organization.member'],\n".
            "     comme TASK-1479 et TASK-1488 l'ont fait ;\n".
            "  2. la surface est VRAIMENT publique -> l'inscrire dans PUBLIC_BY_DECISION\n".
            "     avec la raison, qui devient l'autorite ecrite de sa publicite.\n\n".
            "Un commentaire au-dessus de la route ne suffit pas : c'est precisement\n".
            "ce qui a laisse passer TASK-1488.",
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
        $guestReachable = [];

        foreach ($this->organizationRoutes() as $route) {
            if (! $this->requiresSession($route)) {
                $guestReachable[] = $route->getName() ?? $route->uri();
            }
        }

        $stale = array_diff(array_keys(self::PUBLIC_BY_DECISION), $guestReachable);

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
        Route::prefix('/org/{organization}')
            ->middleware(['web', 'organization'])
            ->name('organization.')
            ->group(function () {
                Route::get('/task1489-fictive-surface', fn () => 'x')->name('task1489.fictive');
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
     * Le predicat suit l'idiome deja en place dans
     * `TASK1473HomeDashboardCollisionTest` : on interroge la chaine COMPLETE
     * (`gatherMiddleware()`, groupes compris), jamais la declaration locale —
     * une route herite tres souvent sa garde de son groupe.
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
     */
    private function requiresSession(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $middleware = (string) $middleware;

            if ($middleware === 'auth'
                || $middleware === 'guest'
                || str_contains($middleware, 'Authenticate')
                || str_contains($middleware, 'RedirectIfAuthenticated')
                || str_contains($middleware, 'EnsureOrganizationMember')
                || str_contains($middleware, 'OrgAdminMiddleware')) {
                return true;
            }
        }

        return false;
    }
}
