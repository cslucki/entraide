<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1604 — une fois DANS une Organization, l'interface y reste.
 *
 * Isolation **UX / navigation**. Cette TASK ne redefinit AUCUNE regle de
 * circulation des donnees entre Organizations : meme vue, meme source, meme
 * contenu. Seuls l'URL, la navigation et la charte changent.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Sessions HTTP reelles sur `https://test.laravel`, base `bouclepro`.
 * `launchpals` : gabarit `artscilab_hero`, locale `en`, non par defaut.
 * `main` : gabarit `bouclepro_hero_v2`, locale `fr`, Organization par defaut.
 *
 * | surface | mesure AVANT |
 * |---|---|
 * | `/org/launchpals` (invite) | lien mentions legales -> **`/mentions-legales`**, le global |
 * | `/org/launchpals/dashboard` (membre) | idem -> **`/mentions-legales`** |
 * | `/org/main` (invite) | mentions legales ET mycelium -> **routes globales** |
 * | `/mycelium` (invite) | **0** occurrence « LaunchPals », **0** lien `/org/launchpals` |
 * | `/org/launchpals/mycelium` | **404** — la route n'existe pas |
 *
 * ## Les deux racines, distinctes
 *
 * - **A — mentions legales** : la route bornee `organization.mentions-legales`
 *   EXISTE deja (TASK-1602). Ce sont les **LIENS** qui visent encore la route
 *   globale : `partials/footer`, `components/app-side-nav`,
 *   `components/mobile-topbar`, `organization/hero-v2`,
 *   `organization/artscilab-hero`.
 * - **B — mycelium** : la route bornee n'existe pas du tout. Seules
 *   `/mycelium` (globale) et `/org/{organization}/constitution` (la
 *   Constitution publiee d'UNE Organization, deja bornee) existent.
 *
 * ## Une borne heritee de TASK-1601 / 1602
 *
 * Le declencheur est ce que l'URL **exprime** (`/org/{slug}/…`), jamais
 * l'Organization devinee par defaut sur une route globale courte. Sans cela on
 * n'isolerait pas un contexte : on en inventerait un.
 *
 * Difference assumee avec `aiOffersUrl()` (TASK-1229), qui exclut
 * l'Organization par defaut : ici l'arbitrage MASTER demande explicitement
 * `/org/main/mentions-legales` et `/org/main/mycelium`. `main` est donc borne
 * comme les autres.
 */
class TASK1604OrganizationUxIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrg;

    private Organization $otherOrg;

    private User $defaultMember;

    private User $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultOrg = Organization::factory()->create([
            'slug' => 'org-1604-defaut',
            'name' => 'Org 1604 Defaut',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now()->subYear(),
        ]);

        $this->otherOrg = Organization::factory()->create([
            'slug' => 'org-1604-autre',
            'name' => 'Org 1604 Autre',
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

        $loop = Loop::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'created_by' => $this->otherMember->id,
        ]);

        LoopMember::query()->create([
            'loop_id' => $loop->id,
            'user_id' => $this->otherMember->id,
            'organization_id' => $this->otherOrg->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    // =====================================================================
    // A. Mentions legales — l'URL suit l'Organization
    // =====================================================================

    public function test_a_an_organization_page_links_to_the_scoped_legal_notice(): void
    {
        $this->oublierOrganisation();

        $html = $this->actingAs($this->otherMember)
            ->get(route('organization.dashboard', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '/org/'.$this->otherOrg->slug.'/mentions-legales',
            $html,
            'une page d\'Organization pointe encore vers les mentions legales GLOBALES'
        );
    }

    /** L'Organization par defaut est bornee comme les autres — arbitrage MASTER. */
    public function test_a_the_default_organization_is_scoped_too(): void
    {
        $this->oublierOrganisation();

        $html = $this->actingAs($this->defaultMember)
            ->get(route('organization.dashboard', ['organization' => $this->defaultOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '/org/'.$this->defaultOrg->slug.'/mentions-legales',
            $html,
            'l\'Organization par defaut n\'est pas bornee alors que l\'arbitrage MASTER le demande'
        );
    }

    /** Le contenu legal reste UNIQUE : la route bornee sert la meme vue. */
    public function test_a_the_legal_content_is_never_duplicated(): void
    {
        $this->assertSame(
            'mentions-legales',
            Route::getRoutes()->getByName('organization.mentions-legales')->defaults['view'] ?? null
        );
        $this->assertSame(
            'mentions-legales',
            Route::getRoutes()->getByName('mentions-legales')->defaults['view'] ?? null
        );
    }

    // =====================================================================
    // B. Mycelium — il existe DANS le contexte d'une Organization
    // =====================================================================

    public function test_b_a_scoped_mycelium_route_exists(): void
    {
        $this->assertTrue(
            Route::has('organization.mycelium'),
            'aucune route Mycelium bornee a une Organization'
        );
    }

    /**
     * CHANGEMENT D'INTENTION PRODUIT — TASK-1692.
     *
     * Ce test s'appelait `test_b_a_guest_sees_mycelium_inside_the_organization`
     * et affirmait l'inverse de ce qu'il affirme aujourd'hui. TASK-1604 avait
     * voulu le Mycelium borne AUSSI public que le Mycelium global ; TASK-1692 a
     * pose `auth` + `organization.member` sur tout le groupe
     * `/org/{organization}` et l'a referme.
     *
     * Ce n'est donc pas une assertion corrigee, c'est une DECISION PRODUIT
     * renversee, et elle est consignee comme telle. La route GLOBALE
     * `/mycelium`, elle, reste publique — l'asymetrie entre les deux est reelle
     * et appartient a MASTER, voir le bloc retire de `PUBLIC_BY_DECISION` dans
     * `TASK1489OrgRouteGuestBoundaryTest`.
     */
    public function test_b_a_guest_is_refused_the_scoped_mycelium_but_keeps_the_global_one(): void
    {
        $this->oublierOrganisation();

        $reponse = $this->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]));

        $reponse->assertRedirect(route('organization.login', ['organization' => $this->otherOrg->slug]));
        $this->assertStringNotContainsString($this->otherOrg->name, $reponse->getContent());

        // La promesse de transparence n'est pas perdue : elle a change d'URL.
        $this->oublierOrganisation();
        $this->get(route('mycelium'))->assertOk();
    }

    public function test_b_a_member_sees_mycelium_inside_their_organization(): void
    {
        $this->oublierOrganisation();

        $html = $this->actingAs($this->otherMember)
            ->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/org/'.$this->otherOrg->slug, $html);
        $this->assertStringContainsString($this->otherOrg->name, $html);
    }

    /**
     * Depuis une page d'Organization, le lien Mycelium y reste — pour qui peut
     * l'ouvrir. TASK-1692 : l'accueil demeure PUBLIC, sa destination non ; le
     * lien n'est donc plus propose a un anonyme, qui n'aurait qu'une
     * redirection au bout.
     */
    public function test_b_an_organization_page_links_to_the_scoped_mycelium_for_its_members(): void
    {
        $this->oublierOrganisation();

        // L'ANONYME d'abord : `actingAs()` ne se defait pas.
        $anonyme = $this->get(route('organization.home', ['organization' => $this->defaultOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            '/org/'.$this->defaultOrg->slug.'/mycelium',
            $anonyme,
            'un lien vers une page fermee est propose a un visiteur anonyme'
        );

        $this->oublierOrganisation();

        $html = $this->actingAs($this->defaultMember)
            ->get(route('organization.home', ['organization' => $this->defaultOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '/org/'.$this->defaultOrg->slug.'/mycelium',
            $html,
            'l\'accueil d\'une Organization pointe encore vers le Mycelium GLOBAL'
        );
        $this->assertStringNotContainsString(
            '/org/'.$this->otherOrg->slug.'/',
            $html,
            'l\'accueil d\'une Organization propose des liens vers une AUTRE Organization'
        );
    }

    /**
     * **Le contenu ne change pas.** Meme vue, meme source, meme information :
     * seule l'enveloppe est bornee. C'est la garde qui empeche cette TASK de
     * deriver vers une redefinition du perimetre des DONNEES.
     */
    public function test_b_the_scoped_mycelium_serves_the_same_content_as_the_global_one(): void
    {
        // Une Organization qui a REELLEMENT publie sa Constitution. Sans elle,
        // les deux listes seraient vides et leur egalite ne mesurerait rien :
        // premiere version de ce test, `pluck('id')` sur des TABLEAUX sans cle
        // `id` comparait deux listes de `null`. Le sabotage l'a revele.
        $publiante = Organization::factory()->create([
            'slug' => 'org-1604-publiante',
            'name' => 'Org 1604 Publiante',
            'is_active' => true,
            'is_public' => true,
            'ai_constitution_public' => true,
        ]);

        OrganizationAiConstitution::query()->create([
            'organization_id' => $publiante->id,
            'version' => 1,
            'body' => 'Constitution publiee de test TASK-1604.',
            'status' => 'active',
            'activated_at' => now(),
        ]);

        // Le GLOBAL d'abord, en anonyme — il est reste public. Puis le borne,
        // sous l'oeil d'un membre : `actingAs()` ne se defait pas, l'ordre
        // inverse aurait mesure deux fois la meme session.
        $this->oublierOrganisation();
        $global = $this->get(route('mycelium'))->assertOk();

        $this->oublierOrganisation();
        $borne = $this->actingAs($this->otherMember)->get(route('organization.mycelium', [
            'organization' => $this->otherOrg->slug,
        ]))->assertOk();

        $slugs = fn ($reponse) => collect($reponse->viewData('organizations'))
            ->pluck('slug')->sort()->values()->all();

        // ATTENTE POSITIVE d'abord : sans elle, l'egalite ci-dessous resterait
        // vraie meme si les deux surfaces se vidaient ensemble — elles
        // partagent le meme controleur.
        $this->assertContains($publiante->slug, $slugs($borne),
            'le Mycelium borne ne publie plus l\'Organization qui a choisi de publier');
        $this->assertContains($publiante->slug, $slugs($global),
            'le Mycelium global ne publie plus l\'Organization qui a choisi de publier');

        $this->assertSame(
            $global->viewData('platformText'),
            $borne->viewData('platformText'),
            'le texte de la Constitution plateforme differe entre les deux surfaces'
        );

        $this->assertSame($slugs($global), $slugs($borne),
            'la liste des Organizations publiees differe : le PERIMETRE DES DONNEES a bouge');
    }

    // =====================================================================
    // C. Rien d'autre ne bouge
    // =====================================================================

    /** Les routes globales restent servies — compatibilite. */
    public function test_c_the_global_routes_still_answer(): void
    {
        $this->oublierOrganisation();
        $this->get(route('mentions-legales'))->assertOk();

        $this->oublierOrganisation();
        $this->get(route('mycelium'))->assertOk();
    }

    /** Une page REELLEMENT globale garde ses liens globaux. */
    public function test_c_a_global_page_keeps_global_links(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('mycelium'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            '/org/'.$this->otherOrg->slug.'/mentions-legales',
            $html,
            'une page globale fuit vers une Organization que le visiteur n\'a pas demandee'
        );
    }

    /**
     * LE DECLENCHEUR EST L'URL, pas le liage.
     *
     * Sur une route GLOBALE courte — ici `/dashboard`, route personnelle —,
     * `ResolveUrlOrganization` lie tout de meme une Organization. Si le helper
     * s'y branchait, un membre y verrait apparaitre `/org/{slug}/…` dans des
     * liens qu'aucune URL n'a bornes : ce ne serait pas conserver un contexte,
     * mais en fabriquer un. C'est la lecon mesuree de TASK-1601 et TASK-1602.
     *
     * Ce test n'existait pas dans la premiere version du fichier. Un sabotage
     * — remplacer la garde d'URL du helper par le seul liage — laissait les 12
     * tests VERTS. La discipline etait revendiquee, pas mesuree.
     */
    public function test_c_a_global_short_route_keeps_global_links(): void
    {
        $this->oublierOrganisation();

        $html = $this->actingAs($this->otherMember)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            '/org/'.$this->otherOrg->slug.'/mentions-legales',
            $html,
            'un lien a ete borne sur une route GLOBALE : le helper suit le liage au lieu de l\'URL'
        );

        $this->assertStringContainsString(
            'href="'.route('mentions-legales').'"',
            $html,
            'la route globale des mentions legales n\'est plus servie sur une page globale'
        );
    }

    /** La garde tenant n'est pas affaiblie. */
    public function test_c_cross_tenant_access_is_still_refused(): void
    {
        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound();
    }

    /**
     * CHANGEMENT D'INTENTION PRODUIT — TASK-1692.
     *
     * S'appelait `test_c_the_scoped_mycelium_stays_public`. Le Mycelium borne
     * est desormais reserve aux membres de SON Organization ; le Mycelium
     * global reste public. Les trois cas sont mesures ensemble, sans quoi un
     * `assertRedirect()` isole serait vert meme si la page avait disparu pour
     * tout le monde.
     */
    public function test_c_the_scoped_mycelium_is_member_only_while_the_global_one_stays_public(): void
    {
        // Anonyme : refuse.
        $this->oublierOrganisation();
        $this->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]))
            ->assertRedirect(route('organization.login', ['organization' => $this->otherOrg->slug]));

        // Global : toujours public.
        $this->oublierOrganisation();
        $this->get(route('mycelium'))->assertOk();

        // Membre d'une AUTRE Organization : 404, pas d'oracle.
        $this->oublierOrganisation();
        $this->actingAs($this->defaultMember)
            ->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]))
            ->assertNotFound();

        // Membre de CETTE Organization : il voit sa page.
        $this->oublierOrganisation();
        $this->actingAs($this->otherMember->fresh())
            ->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->assertSee($this->otherOrg->name);
    }
}
