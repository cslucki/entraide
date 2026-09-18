<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1601 — un membre d'une Organization qui n'est PAS l'Organization
 * plateforme par defaut recevait un 404 apres son login.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Sessions HTTP reelles, sur `bouclepro_prod_work` (copie de PROD 1.595) ET sur
 * le banc `https://test.laravel`. Parcours identique, resultat identique :
 *
 * | Etape | Resultat |
 * |---|---|
 * | invite `GET /mycelium` | 200 |
 * | invite `GET /loops` | 302 -> `/login` |
 * | `POST /login` | 302 -> `/loops` (l'`intended` est respecte) |
 * | `GET /dashboard` | 200 — l'utilisateur EST authentifie |
 * | **`GET /loops`** | **404** |
 * | `GET /org/{sa-org}/loops` | 302 — le chemin borne, lui, fonctionne |
 * | `GET /org/main/loops` | 404 — refus cross-tenant, correct |
 *
 * Contraste decisif, meme code, meme runtime : 2 membres `launchpals` -> 404,
 * 3 membres `main` -> 200. Le defaut ne se declenche que lorsque
 * l'Organization de l'utilisateur n'est PAS l'Organization par defaut.
 *
 * ## La chaine
 *
 * 1. `ResolveUrlOrganization::$defaultOrganizationRoutes` contient `'loops'` ;
 * 2. `$authenticatedPersonalRoutes` ne contient QUE `'dashboard'` ;
 * 3. donc `/loops` tombe sur `resolveDefaultOrganization()`, qui lie
 *    l'Organization plateforme par defaut — un tenant ETRANGER a l'utilisateur ;
 * 4. `LoopController::assertUserBelongsToOrganization()` refuse, a juste titre.
 *
 * **La garde tenant faisait son travail. Le defaut etait en amont** : lier
 * implicitement une Organization etrangere a une requete AUTHENTIFIEE.
 *
 * ## Le correctif, et ses bornes
 *
 * Pour un utilisateur connecte sur une route COURTE de fonctionnalite :
 * - on ne lie plus jamais l'Organization par defaut, mais la SIENNE ;
 * - et si son Organization n'est pas celle par defaut, on l'envoie sur la forme
 *   canonique `/org/{slug}/{feature}` — **uniquement si cette route existe**.
 *
 * `search` et `reports` n'ont pas d'equivalent `/org/{organization}/…` : on ne
 * fabrique aucune route inexistante, on se contente de lier la bonne
 * Organization. La section D en fait un test.
 *
 * Un membre de l'Organization par defaut ne voit AUCUN changement : l'URL
 * courte est deja la sienne. La section B en fait un test.
 */
class TASK1601PostLoginTenantRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrg;

    private Organization $otherOrg;

    private User $defaultMember;

    private User $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        // L'ordre de creation fait l'Organization par defaut :
        // `resolveDefaultOrganization()` trie par created_at puis id.
        $this->defaultOrg = Organization::factory()->create([
            'slug' => 'org-1601-defaut',
            'name' => 'Org 1601 Defaut',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now()->subYear(),
        ]);

        $this->otherOrg = Organization::factory()->create([
            'slug' => 'org-1601-autre',
            'name' => 'Org 1601 Autre',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now(),
        ]);

        $this->defaultMember = User::factory()->complete()->create([
            'organization_id' => $this->defaultOrg->id,
        ]);

        $this->otherMember = User::factory()->complete()->create([
            'organization_id' => $this->otherOrg->id,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le bug reproduit — c'est cette section qui doit ROUGIR avant le correctif
    // =====================================================================

    public function test_a_member_of_a_non_default_organization_is_sent_to_their_canonical_loops(): void
    {
        $this->actingAs($this->otherMember)
            ->get('/loops')
            ->assertRedirect(route('organization.loops.index', [
                'organization' => $this->otherOrg->slug,
            ], false));
    }

    /**
     * Le parcours COMPLET du rapport : invite -> /loops -> login -> intended ->
     * destination.
     *
     * `forgetInstance('current_organization')` entre les etapes n'est pas une
     * commodite : c'est la FRONTIERE DE REQUETE. En production chaque requete
     * HTTP part d'un conteneur neuf (l'environnement n'utilise pas Octane —
     * `usesOctane = false`), donc `alreadyResolved()` y est toujours faux au
     * premier middleware. Dans un seul processus de test, le liage pose par la
     * requete INVITE survivrait et court-circuiterait la resolution — on
     * mesurerait le harnais, pas le produit. Le parcours a par ailleurs ete
     * reproduit en sessions HTTP reelles (curl, processus distincts) sur
     * `prod_work` et sur le banc.
     */
    public function test_the_full_guest_to_login_journey_lands_on_the_canonical_route(): void
    {
        $this->get('/loops')->assertRedirect(route('login', [], false));

        app()->forgetInstance('current_organization');

        $this->post('/login', [
            'email' => $this->otherMember->email,
            'password' => 'password',
        ])->assertRedirect('/loops');

        app()->forgetInstance('current_organization');

        // L'`intended` est respecte ; c'est la destination qui etait fausse.
        $this->get('/loops')->assertRedirect(route('organization.loops.index', [
            'organization' => $this->otherOrg->slug,
        ], false));
    }

    /**
     * Aucune Organization ETRANGERE ne doit etre liee a une requete
     * authentifiee. Deux issues sont acceptables, une seule est interdite :
     * ne rien lier (on a redirige avant), ou lier la SIENNE. Lier celle d'un
     * autre tenant, jamais.
     */
    public function test_no_foreign_organization_is_bound_on_a_short_feature_route(): void
    {
        app()->forgetInstance('current_organization');

        $this->actingAs($this->otherMember)->get('/loops');

        $bound = app()->bound('current_organization') ? app('current_organization') : null;

        if ($bound !== null) {
            $this->assertSame(
                $this->otherOrg->id,
                $bound->id,
                'une Organization ETRANGERE a ete liee a une requete authentifiee'
            );
        }

        $this->assertNotSame(
            $this->defaultOrg->id,
            $bound?->id,
            'l\'Organization par defaut a ete liee a un membre d\'un autre tenant'
        );
    }

    // =====================================================================
    // B. Le membre de l'Organization par defaut ne perd rien
    // =====================================================================

    public function test_a_member_of_the_default_organization_still_gets_the_short_url(): void
    {
        $this->actingAs($this->defaultMember)
            ->get('/loops')
            ->assertOk();
    }

    // =====================================================================
    // C. La garde tenant n'est PAS affaiblie
    // =====================================================================

    public function test_a_stranger_is_still_refused_on_another_organization_scoped_route(): void
    {
        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound();
    }

    /** Un invite n'est pas redirige vers un tenant : il passe par la porte de login. */
    public function test_a_guest_still_goes_to_login(): void
    {
        $this->get('/loops')->assertRedirect(route('login', [], false));
    }

    // =====================================================================
    // D. Aucune route inexistante n'est fabriquee
    // =====================================================================

    /**
     * `search` et `reports` sont dans `$defaultOrganizationRoutes` mais n'ont
     * AUCUN equivalent `/org/{organization}/…`. Les rediriger fabriquerait un
     * 404 la ou il n'y en avait pas.
     */
    public function test_features_without_a_canonical_organization_route_are_never_redirected(): void
    {
        foreach (['search', 'reports'] as $feature) {
            $this->assertFalse(
                Route::has('organization.'.$feature),
                "la premisse a change : organization.{$feature} existe desormais"
            );

            app()->forgetInstance('current_organization');

            $response = $this->actingAs($this->otherMember)->get('/'.$feature);

            $this->assertNotEquals(
                302,
                $response->getStatusCode(),
                "/{$feature} a ete redirige vers une route canonique inexistante"
            );
        }
    }

    /**
     * LE RESIDU, epingle pour qu'il ne se perde pas.
     *
     * `/search` n'a aucune route bornee : la redirection ne peut pas
     * s'appliquer, et l'Organization PAR DEFAUT reste liee a un membre d'un
     * autre tenant. Le meme residu vaut pour les chemins profonds
     * (`/messages/{user}`).
     *
     * Le corriger demanderait de resoudre depuis l'utilisateur dans
     * `resolveOrganization()` — mesure faite : **8 tests** de TASK-1288 / 1289 /
     * 1291 rougissent, car ils defendent la semantique actuelle. C'est une
     * redefinition, pas un correctif ; elle appartient au FOLLOW-UP.
     *
     * Ce test n'approuve pas ce comportement : il le DATE. Le jour ou le
     * follow-up sera fait, il rougira, et ce sera le bon signal.
     */
    public function test_a_feature_without_canonical_route_keeps_the_known_residual(): void
    {
        $this->assertFalse(Route::has('organization.search'), 'la premisse a change');

        app()->forgetInstance('current_organization');

        $this->actingAs($this->otherMember)->get('/search');

        $bound = app()->bound('current_organization') ? app('current_organization') : null;

        $this->assertNotNull($bound, 'aucune Organization liee sur /search');
        $this->assertSame(
            $this->defaultOrg->id,
            $bound->id,
            'RESIDU CORRIGE : /search ne lie plus l\'Organization par defaut — mettre a jour le follow-up'
        );
    }

    /** La liste des routes de fonctionnalite reste la seule autorite. */
    public function test_the_feature_route_list_is_unchanged(): void
    {
        $this->assertContains('loops', ResolveUrlOrganization::$defaultOrganizationRoutes);
        $this->assertContains('dashboard', ResolveUrlOrganization::$authenticatedPersonalRoutes);
    }
}
