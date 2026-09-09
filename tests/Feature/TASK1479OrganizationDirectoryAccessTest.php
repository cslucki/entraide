<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    /** Le predicat n'est pas reecrit : c'est celui d'`OrgAdminMiddleware`. */
    public function test_the_transverse_predicate_is_the_existing_one(): void
    {
        $ours = php_strip_whitespace(app_path('Http/Middleware/EnsureOrganizationMember.php'));
        $existing = php_strip_whitespace(app_path('Http/Middleware/OrgAdminMiddleware.php'));

        $this->assertStringContainsString('$user->is_admin', $ours);
        $this->assertStringContainsString('is_admin', $existing);

        // Et aucune autre porte n'a ete ouverte au passage.
        foreach (['is_public', 'admin_id', 'Gate::', 'config('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $ours, $forbidden.' : aucun critere de plus');
        }
    }

    // =====================================================================
    // E. Le perimetre : ce qui n'a PAS bouge
    // =====================================================================

    /** `/loops` etait deja protegee ; elle ne change pas. */
    public function test_loops_are_untouched(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('organization.loops.index');
        $middleware = $route->gatherMiddleware();

        // `gatherMiddleware()` rend les ALIAS declares sur la route (`auth`,
        // `organization.member`), pas les classes resolues : `route:list` les
        // resout, cette API non. Une assertion sur `Authenticate` etait donc
        // fausse pour une bonne raison — mesure faite, elle rougissait.
        $this->assertContains('auth', $middleware, '/loops etait deja authentifiee');
        $this->assertNotContains('organization.member', $middleware,
            'hors perimetre : on ne touche pas aux Boucles cette nuit');
    }

    /** Le blog public reste public : il n'etait pas dans le finding. */
    public function test_the_public_blog_is_untouched(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('organization.blog.index');

        $this->assertNotContains('organization.member', $route->gatherMiddleware());
    }

    /**
     * `organization.dashboard` reste hors perimetre. Le finding le concernant
     * a ete mesure : la route rend 200 pour un membre d'une autre
     * Organization, mais AUCUNE donnee de l'Organization visitee n'apparait —
     * le controleur ne lit que l'utilisateur connecte. Il n'est donc pas P0, et
     * l'inclure ici aurait etendu le perimetre d'un correctif d'urgence.
     */
    public function test_the_dashboard_stays_out_of_scope(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('organization.dashboard');

        $this->assertNotContains('organization.member', $route->gatherMiddleware());
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
            $middleware = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware();

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
            $middleware = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware();

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
