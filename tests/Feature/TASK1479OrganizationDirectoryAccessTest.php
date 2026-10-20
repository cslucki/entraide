<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1479 (P0 privacy) — l'annuaire et les echanges d'une Organization
 * cessent d'etre servis a n'importe qui.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * `curl` sans aucun cookie, sur le banc :
 *
 * | Organization | `is_public` | `/membres` |
 * |---|---|---|
 * | `artscilab-en` | **false** | **200** — « 5 registered members », noms, villes, biographies |
 * | `audit-1014-alpha` | **false** | **200** — 3 membres |
 * | `test20260822` | **false** | **200** — 4 membres |
 * | `launchpals` | true | 200 — 7 membres |
 * | `/membres` (sans prefixe) | — | **200** — 42 membres de l'Organization par defaut |
 *
 * Chaine de middlewares complete des routes concernees :
 * `web | ResolveOrganization`. Ni authentification, ni appartenance, ni
 * verification de publicite.
 *
 * ## Ou etait le defaut
 *
 * PAS dans la requete SQL. `HomeController@members` filtre correctement sur
 * `organization_id` : elle est bornee au tenant VISITE. Elle sert donc le
 * tenant que l'URL designe **a qui le demande**, sans jamais demander qui le
 * demande.
 *
 * Le correctif porte sur QUI atteint le controleur. Aucune donnee n'a change de
 * visibilite, aucun champ n'est masque, aucune requete n'est reecrite — ce
 * serait un faux correctif, et le CDC l'interdit explicitement.
 *
 * ## Les deux gardes sont necessaires, et le prouver est le coeur du fichier
 *
 * `auth` seul fermerait l'acces anonyme en laissant le cross-tenant
 * AUTHENTIFIE ouvert. La section C existe pour rougir dans ce cas precis.
 */
class TASK1479OrganizationDirectoryAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Les six routes mesurees comme ouvertes, sous leurs deux formes. */
    private const PREFIXED = ['organization.members.index', 'organization.explorer', 'organization.exchanges.index'];

    private const LEGACY = ['members.index', 'explorer', 'exchanges.index'];

    /**
     * TASK-1479, extension arbitree par MASTER : la fiche individuelle.
     *
     * « Profil public » veut dire visible des AUTRES MEMBRES de l'Organization,
     * pas ouvert au Web anonyme. Le signal d'interface — la navigation nomme
     * cette page « Mon profil public » — ne suffisait pas a autoriser une
     * exposition Internet : sur une Organization `is_public = false`, la page
     * rendait 200 a un anonyme avec nom, ville, biographie, disponibilite et
     * points.
     *
     * Fermer l'annuaire en laissant chaque fiche accessible aurait ete un
     * demi-correctif : connaitre l'UUID reduit la decouvrabilite, pas la
     * gravite de l'autorisation manquante.
     */
    private const PROFILE = ['organization.profile.show', 'profile.show'];

    private Organization $private;

    private Organization $public;

    private User $memberOfPrivate;

    private User $memberOfPublic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->private = Organization::factory()->create([
            'is_active' => true,
            'is_public' => false,
            'slug' => 'org-1479-privee',
            'name' => 'Org 1479 Privee',
        ]);

        $this->public = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-1479-publique',
            'name' => 'Org 1479 Publique',
        ]);

        $this->memberOfPrivate = User::factory()->complete()->create([
            'organization_id' => $this->private->id,
            'name' => 'Amina Diallo',
            'city' => 'Marseille',
            'bio' => 'Ethique et engagement public.',
        ]);

        $this->memberOfPublic = User::factory()->complete()->create([
            'organization_id' => $this->public->id,
            'name' => 'Tomas Vieira',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Anonyme : jamais de contenu, sur AUCUNE Organization
    // =====================================================================

    public function test_an_anonymous_visitor_never_reaches_a_private_organization_directory(): void
    {
        foreach (self::PREFIXED as $name) {
            $response = $this->get(route($name, ['organization' => $this->private->slug]));

            $this->assertNotSame(200, $response->getStatusCode(), "[{$name}] doit etre ferme a un anonyme");
            $this->assertLeaksNothing($response->getContent(), $name);
        }
    }

    /**
     * L'Organization PUBLIQUE est fermee elle aussi. C'est la decision prise :
     * `is_public` gouverne la vitrine de l'Organization, pas l'exposition
     * nominative de ses membres. Une vitrine publique reduite reste possible,
     * mais c'est une decision produit distincte — pas un correctif de fuite.
     */
    public function test_an_anonymous_visitor_is_blocked_on_a_public_organization_too(): void
    {
        foreach (self::PREFIXED as $name) {
            $response = $this->get(route($name, ['organization' => $this->public->slug]));

            $this->assertNotSame(200, $response->getStatusCode(), "[{$name}] : `is_public` n'ouvre pas l'annuaire");
            $this->assertLeaksNothing($response->getContent(), $name);
        }
    }

    /** La forme NON prefixee fuyait aussi — 42 personnes de l'Organization par defaut. */
    public function test_the_unprefixed_routes_are_closed_to_anonymous_visitors_as_well(): void
    {
        foreach (self::LEGACY as $name) {
            $response = $this->get(route($name));

            $this->assertNotSame(200, $response->getStatusCode(), "[{$name}] doit etre ferme a un anonyme");
            $this->assertLeaksNothing($response->getContent(), $name);
        }
    }

    // =====================================================================
    // B. Le membre garde son acces, inchange
    // =====================================================================

    public function test_a_member_still_reaches_the_directory_of_their_own_organization(): void
    {
        foreach (self::PREFIXED as $name) {
            $this->actingAs($this->memberOfPrivate)
                ->get(route($name, ['organization' => $this->private->slug]))
                ->assertOk();
        }
    }

    /** Et il y voit bien ses collegues : le correctif n'a masque aucun champ. */
    public function test_the_member_sees_the_directory_content_unchanged(): void
    {
        $colleague = User::factory()->complete()->create([
            'organization_id' => $this->private->id,
            'name' => 'Nadia Berrada',
        ]);

        $html = $this->actingAs($this->memberOfPrivate)
            ->get(route('organization.members.index', ['organization' => $this->private->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e($colleague->name), $html);
        $this->assertStringContainsString(e($this->memberOfPrivate->name), $html);
    }

    // =====================================================================
    // C. Cross-tenant AUTHENTIFIE — la garde que `auth` seul laisserait passer
    // =====================================================================

    /**
     * **Le test central.** Retirer `organization.member` en gardant `auth`
     * fermerait l'anonyme et laisserait grande ouverte la traversee entre
     * tenants. Ce test rougit exactement dans ce cas — et le sabotage a ete
     * joue pour le verifier.
     */
    public function test_a_member_of_another_organization_never_reaches_the_directory(): void
    {
        foreach (self::PREFIXED as $name) {
            $response = $this->actingAs($this->memberOfPublic)
                ->get(route($name, ['organization' => $this->private->slug]));

            $this->assertSame(404, $response->getStatusCode(), "[{$name}] : Organization = Tenant");
            $this->assertLeaksNothing($response->getContent(), $name);
        }
    }

    /** Et dans l'autre sens, pour que la garde ne soit pas orientee par accident. */
    public function test_the_boundary_holds_in_both_directions(): void
    {
        $response = $this->actingAs($this->memberOfPrivate)
            ->get(route('organization.members.index', ['organization' => $this->public->slug]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString($this->memberOfPublic->name, (string) $response->getContent());
    }

    // =====================================================================
    // D. Le SuperAdmin : l'autorite EXISTANTE, pas une nouvelle
    // =====================================================================

    /**
     * `is_admin` est exactement le predicat que `OrgAdminMiddleware` utilise
     * deja pour accorder un acces transverse. On le reprend tel quel : ouvrir
     * un nouveau contournement a l'occasion d'un correctif de fuite serait le
     * contraire du but.
     */
    public function test_the_superadmin_keeps_the_transverse_access_the_repository_already_grants(): void
    {
        $superAdmin = User::factory()->complete()->create([
            'organization_id' => $this->public->id,
            'is_admin' => true,
        ]);

        $this->actingAs($superAdmin)
            ->get(route('organization.members.index', ['organization' => $this->private->slug]))
            ->assertOk();
    }

    /**
     * Le predicat n'est pas reecrit : c'est celui d'`OrgAdminMiddleware`.
     *
     * ## Pourquoi ce test lit `handle()` et non le fichier entier
     *
     * Il lisait le fichier entier, et TASK-1483 l'a fait rougir sur `is_public`
     * — a bon droit : c'est exactement le mot qu'il surveille. Mais TASK-1483
     * n'ouvre aucune porte avec : `is_public` n'y apparait que dans
     * `explain()`, c'est-a-dire APRES que le refus est deja decide, et pour
     * regler une seule chose — si l'on peut NOMMER l'Organization refusee.
     *
     * Elargir la garde a « nulle part dans le fichier » l'aurait rendue vraie
     * mais aveugle ; la supprimer aurait rendu le fichier libre. On la resserre
     * donc sur ce qu'elle protege reellement : **la methode qui decide**. Si
     * `is_public`, `admin_id`, un `Gate::` ou une cle de config entre un jour
     * dans le chemin de decision, ce test rougit toujours.
     */
    public function test_the_transverse_predicate_is_the_existing_one(): void
    {
        $ours = php_strip_whitespace(app_path('Http/Middleware/EnsureOrganizationMember.php'));
        $existing = php_strip_whitespace(app_path('Http/Middleware/OrgAdminMiddleware.php'));

        $this->assertStringContainsString('$user->is_admin', $ours);
        $this->assertStringContainsString('is_admin', $existing);

        // Et aucune autre porte n'a ete ouverte au passage, DANS LE CHEMIN DE
        // DECISION. La source vient de la reflexion, pas d'un decoupage de
        // chaine : le jour ou la methode bouge, on lit toujours la bonne.
        $decision = $this->sourceOfDecisionPath();

        $this->assertStringContainsString('$user->is_admin', $decision, 'premisse : `handle()` est bien le chemin de decision');

        foreach (['is_public', 'admin_id', 'Gate::', 'config('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $decision, $forbidden.' : aucun critere de plus');
        }
    }

    /** Le corps de `EnsureOrganizationMember::handle()`, tel qu'il est ecrit. */
    private function sourceOfDecisionPath(): string
    {
        $method = new \ReflectionMethod(EnsureOrganizationMember::class, 'handle');
        $lines = file($method->getFileName());

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }

    // =====================================================================
    // E. Le perimetre : ce qui n'a PAS bouge
    // =====================================================================

    /**
     * TASK-1692 — la pile EFFECTIVE, pas les alias declares.
     *
     * `gatherMiddleware()` rend les alias de la route et de ses groupes, mais
     * ne soustrait PAS `withoutMiddleware()`. Depuis que TASK-1692 pose
     * `auth` + `organization.member` sur le groupe `/org/{organization}` et en
     * excepte certaines routes, raisonner sur cette API donne une reponse
     * fausse — c'est le defaut qui avait desarme la garde de TASK-1489.
     * `Router::gatherRouteMiddleware()` resout les alias ET applique les
     * exclusions : c'est la seule lecture qui dise ce qui S'EXECUTE.
     *
     * @return list<string> classes resolues, parametre compris
     */
    private function effectiveMiddleware(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "[{$routeName}] a disparu du routeur.");

        return array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            static fn ($m): bool => is_string($m),
        ));
    }

    /**
     * CHANGEMENT DE CONTRAT — TASK-1692.
     *
     * Cette methode s'appelait `test_loops_are_untouched` et affirmait
     * `assertNotContains('organization.member')` : TASK-1479 etait un correctif
     * d'URGENCE, et ce test etait sa garde contre la SUR-EXTENSION — « hors
     * perimetre : on ne touche pas aux Boucles cette nuit ».
     *
     * Elle a fait son travail : elle a sonne le jour ou TASK-1692 a etendu la
     * garde a toutes les routes internes prefixees. L'extension est desormais
     * la decision produit, et c'est elle que cette methode mesure — sans quoi
     * la garde continuerait de defendre un perimetre qui n'existe plus.
     *
     * Ce qu'on ne fait PAS : remplacer `assertNotContains` par
     * `assertContains` et s'arreter la. Une assertion sur la pile ne dit rien
     * de ce que la pile FAIT. Les quatre comportements sont donc mesures.
     */
    public function test_the_prefixed_loops_now_require_authentication_and_membership(): void
    {
        $effective = $this->effectiveMiddleware('organization.loops.index');

        $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $effective, '/loops etait deja authentifiee');
        $this->assertContains(EnsureOrganizationMember::class, $effective, 'TASK-1692 : toute surface interne prefixee demande l\'appartenance');

        $url = route('organization.loops.index', ['organization' => $this->private->slug]);

        // 1. Anonyme : jamais servi.
        $anonyme = $this->get($url);
        $this->assertNotSame(200, $anonyme->getStatusCode(), 'un anonyme ne doit pas atteindre les Boucles d\'une Organization');
        $this->assertLeaksNothing($anonyme->getContent(), 'organization.loops.index');

        // 2. Membre d'une AUTRE Organization : 404, et rien de nomme — c'est
        //    le refus muet, pas le refus explique du tableau de bord.
        $etranger = $this->actingAs($this->memberOfPublic)->get($url);
        $etranger->assertNotFound();
        $etranger->assertDontSee($this->private->name, false);

        // 3. Membre legitime : la garde ne lui ferme pas sa propre porte.
        $legitime = $this->actingAs($this->memberOfPrivate)->get($url);
        $this->assertNotContains(
            $legitime->getStatusCode(),
            [403, 404],
            'la garde d\'appartenance refuse un membre de SA propre Organization',
        );
    }

    /**
     * Et la garde n'OUVRE rien au passage : appartenir a l'Organization ne
     * rend membre d'aucune Boucle.
     *
     * Organization = Tenant, mais Loop != Tenant. Le middleware repond a
     * « cette personne appartient-elle au tenant ? » et a rien d'autre : il
     * n'ecrit pas, n'accorde pas, ne cree aucune appartenance. Le mesurer ici
     * evite qu'une future garde « pratique » prenne l'habitude inverse.
     */
    public function test_passing_the_membership_guard_never_grants_loop_membership(): void
    {
        $avant = \App\Models\LoopMember::query()->count();

        $this->actingAs($this->memberOfPrivate)
            ->get(route('organization.loops.index', ['organization' => $this->private->slug]));

        $this->assertSame($avant, \App\Models\LoopMember::query()->count(),
            'franchir la garde d\'appartenance a cree une appartenance de Boucle');
    }

    /**
     * CHANGEMENT DE CONTRAT — TASK-1692.
     *
     * S'appelait `test_the_public_blog_is_untouched`. Le blog PREFIXE n'est
     * plus public ; la route GLOBALE `/blog`, elle, ne change pas — et c'est
     * la contre-epreuve qui empeche ce test de dire simplement « tout est
     * ferme », ce qui serait vrai meme si on avait tout casse.
     */
    public function test_the_prefixed_blog_requires_membership_while_the_global_one_is_untouched(): void
    {
        $effective = $this->effectiveMiddleware('organization.blog.index');

        $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $effective);
        $this->assertContains(EnsureOrganizationMember::class, $effective);

        $prefixe = route('organization.blog.index', ['organization' => $this->private->slug]);

        $anonyme = $this->get($prefixe);
        $this->assertNotSame(200, $anonyme->getStatusCode());
        $this->assertLeaksNothing($anonyme->getContent(), 'organization.blog.index');

        $this->actingAs($this->memberOfPublic)->get($prefixe)
            ->assertNotFound()
            ->assertDontSee($this->private->name, false);

        $this->actingAs($this->memberOfPrivate)->get($prefixe)->assertOk();

        // CONTRE-EPREUVE — la route globale n'a pas bouge.
        $globale = $this->effectiveMiddleware('blog.index');
        $this->assertNotContains(EnsureOrganizationMember::class, $globale,
            'TASK-1692 borne les routes PREFIXEES ; la route globale n\'est pas de son ressort');
    }

    /**
     * `organization.dashboard` etait HORS PERIMETRE de TASK-1479, et le
     * disait. **TASK-1483 l'a prise**, avec le mode `explain`.
     *
     * TASK-1692 — mesure sur la pile EFFECTIVE. `gatherMiddleware()` rendait
     * ici `organization.member` ET `organization.member:explain`, parce qu'il
     * ignore le `withoutMiddleware(['organization.member'])` que TASK-1692 a
     * pose sur cette route : l'assertion d'exclusion etait donc fausse, et le
     * serait restee sans cette correction.
     *
     * Ce que la methode mesure n'a pas change : **l'annuaire refuse en 404, le
     * tableau de bord refuse en 403 explique**. Unifier les deux refus rendrait
     * l'une des deux assertions rouge — et c'est exactement ce qu'on veut
     * savoir.
     */
    public function test_the_dashboard_refuses_differently_from_the_directory(): void
    {
        $dashboard = $this->effectiveMiddleware('organization.dashboard');

        // Le tableau de bord : le mode qui EXPLIQUE (TASK-1483), et LUI SEUL.
        $this->assertContains(EnsureOrganizationMember::class.':'.EnsureOrganizationMember::MODE_EXPLAIN, $dashboard);
        $this->assertNotContains(EnsureOrganizationMember::class, $dashboard,
            'le tableau de bord ne doit pas retomber sur le 404 muet');

        // L'annuaire : le refus par defaut, un 404 — un 403 y confirmerait a un
        // tiers que cette Organization existe.
        foreach (self::PREFIXED as $name) {
            $middleware = $this->effectiveMiddleware($name);

            $this->assertContains(EnsureOrganizationMember::class, $middleware, "[{$name}] : le refus muet");
            $this->assertNotContains(EnsureOrganizationMember::class.':'.EnsureOrganizationMember::MODE_EXPLAIN, $middleware,
                "[{$name}] : nommer l'Organization ici la confirmerait");
        }
    }

    /**
     * La CONTRE-EPREUVE de COMPORTEMENT — une pile ne dit pas ce qu'elle fait.
     *
     * ECART MESURE, A ARBITRER : le mode `explain` rend **403 dans les deux
     * cas**, Organization etrangere publique ou privee. Le no-oracle n'y passe
     * pas par le STATUT mais par le NOM — `EnsureOrganizationMember::explain()`
     * ne transmet `organizationName` et `publicHomeUrl` que si `is_public` est
     * vrai, et sur une Organization privee la page de refus ne nomme rien.
     * Un 403 confirme neanmoins qu'il y a quelque chose a cette URL, la ou
     * l'annuaire rend 404. C'est une decision de TASK-1483, pas un defaut de
     * TASK-1692 ; la changer demanderait de toucher au middleware, hors
     * perimetre. Ce test mesure donc ce qui EST, et l'ecrit.
     */
    public function test_the_dashboard_explains_a_public_organization_and_stays_a_no_oracle_404_on_a_private_one(): void
    {
        foreach ([
            ['publique', $this->public, $this->memberOfPrivate, true],
            ['privee', $this->private, $this->memberOfPublic, false],
        ] as [$etiquette, $organisation, $etranger, $doitNommer]) {
            $refus = $this->actingAs($etranger->fresh())
                ->get(route('organization.dashboard', ['organization' => $organisation->slug]));

            if ($doitNommer) {
                // PUBLIQUE : le refus explique, et nomme — decision TASK-1483,
                // inchangee. Son existence est deja affichee par sa landing.
                $refus->assertForbidden();
                $refus->assertSee($organisation->name, false);
            } else {
                // PRIVEE : 404, STRICTEMENT identique a un slug inexistant.
                // Un 403 confirmait l'existence ; le nom tu ne suffisait pas.
                $refus->assertNotFound();
                $refus->assertDontSee($organisation->name, false);
                $this->assertLeaksNothing($refus->getContent(), "dashboard {$etiquette}");

                $inexistant = $this->actingAs($etranger->fresh())
                    ->get('/org/org-1479-inexistante/dashboard');

                $this->assertSame(
                    $inexistant->getStatusCode(),
                    $refus->getStatusCode(),
                    'une Organization privee doit repondre comme un slug inexistant',
                );
            }

            // L'annuaire de la MEME Organization refuse muet : la distinction
            // introduite par TASK-1483 doit survivre a TASK-1692.
            $this->actingAs($etranger->fresh())
                ->get(route('organization.members.index', ['organization' => $organisation->slug]))
                ->assertNotFound()
                ->assertDontSee($organisation->name, false);
        }
    }

    /**
     * Et le membre legitime passe les deux portes — sans quoi les deux tests
     * ci-dessus seraient verts sur une porte muree pour tout le monde.
     */
    public function test_a_legitimate_member_still_reaches_their_dashboard_and_directory(): void
    {
        $this->actingAs($this->memberOfPrivate)
            ->get(route('organization.dashboard', ['organization' => $this->private->slug]))
            ->assertOk();

        $this->actingAs($this->memberOfPrivate->fresh())
            ->get(route('organization.members.index', ['organization' => $this->private->slug]))
            ->assertOk();
    }

    /** Aucune migration : la frontiere est une regle, pas une colonne. */
    public function test_no_migration_ships_with_this_fix(): void
    {
        $this->assertSame([], glob(database_path('migrations/*organization_member*.php')) ?: []);
        $this->assertSame([], glob(database_path('migrations/*directory_access*.php')) ?: []);
    }

    /** Les six routes portent bien les DEUX gardes — un inventaire, pas un echantillon. */
    public function test_all_six_routes_carry_both_guards(): void
    {
        foreach ([...self::PREFIXED, ...self::LEGACY] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('auth', $middleware, "[{$name}] : l'acces anonyme");
            $this->assertContains('organization.member', $middleware, "[{$name}] : le cross-tenant");
        }
    }

    // =====================================================================
    // F. La fiche individuelle suit la meme frontiere
    // =====================================================================

    public function test_an_anonymous_visitor_never_reaches_an_individual_profile(): void
    {
        $response = $this->get(route('organization.profile.show', [
            'organization' => $this->private->slug,
            'user' => $this->memberOfPrivate->id,
        ]));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertLeaksNothing($response->getContent(), 'profile.show');
    }

    public function test_a_member_of_another_organization_never_reaches_an_individual_profile(): void
    {
        $response = $this->actingAs($this->memberOfPublic)->get(route('organization.profile.show', [
            'organization' => $this->private->slug,
            'user' => $this->memberOfPrivate->id,
        ]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertLeaksNothing($response->getContent(), 'profile.show');
    }

    /** Et le membre voit toujours la fiche de ses collegues : rien n'est masque. */
    public function test_a_member_still_reaches_a_profile_of_their_own_organization(): void
    {
        $colleague = User::factory()->complete()->create([
            'organization_id' => $this->private->id,
            'name' => 'Yacine Bouazza',
        ]);

        $html = $this->actingAs($this->memberOfPrivate)->get(route('organization.profile.show', [
            'organization' => $this->private->slug,
            'user' => $colleague->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString(e($colleague->name), $html);
    }

    /** Les deux formes de la route portent les deux gardes. */
    public function test_both_profile_route_forms_carry_both_guards(): void
    {
        foreach (self::PROFILE as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('auth', $middleware, "[{$name}]");
            $this->assertContains('organization.member', $middleware, "[{$name}]");
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Aucun nom, aucune ville, aucune biographie de l'Organization protegee. */
    private function assertLeaksNothing(string $html, string $context): void
    {
        foreach ([$this->memberOfPrivate->name, 'Marseille', 'Ethique et engagement public.'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "[{$context}] fuite : {$needle}");
        }
    }
}
