<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
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

    public function test_b_a_guest_sees_mycelium_inside_the_organization(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '/org/'.$this->otherOrg->slug,
            $html,
            'Mycelium borne ne porte aucune navigation de l\'Organization'
        );
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

    /** Depuis une page d'Organization, le lien Mycelium y reste. */
    public function test_b_an_organization_page_links_to_the_scoped_mycelium(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.home', ['organization' => $this->defaultOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '/org/'.$this->defaultOrg->slug.'/mycelium',
            $html,
            'l\'accueil d\'une Organization pointe encore vers le Mycelium GLOBAL'
        );
    }

    /**
     * **Le contenu ne change pas.** Meme vue, meme source, meme information :
     * seule l'enveloppe est bornee. C'est la garde qui empeche cette TASK de
     * deriver vers une redefinition du perimetre des DONNEES.
     */
    public function test_b_the_scoped_mycelium_serves_the_same_content_as_the_global_one(): void
    {
        $this->oublierOrganisation();
        $borne = $this->get(route('organization.mycelium', [
            'organization' => $this->otherOrg->slug,
        ]))->assertOk();

        $this->oublierOrganisation();
        $global = $this->get(route('mycelium'))->assertOk();

        $this->assertSame(
            $global->viewData('platformText'),
            $borne->viewData('platformText'),
            'le texte de la Constitution plateforme differe entre les deux surfaces'
        );

        $this->assertEquals(
            $global->viewData('organizations')->pluck('id')->sort()->values()->all(),
            $borne->viewData('organizations')->pluck('id')->sort()->values()->all(),
            'la liste des Organizations publiees differe : le PERIMETRE DES DONNEES a bouge'
        );
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

    /** La garde tenant n'est pas affaiblie. */
    public function test_c_cross_tenant_access_is_still_refused(): void
    {
        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound();
    }

    /** Mycelium borne reste PUBLIC, comme le Mycelium global. */
    public function test_c_the_scoped_mycelium_stays_public(): void
    {
        $this->oublierOrganisation();

        $this->get(route('organization.mycelium', ['organization' => $this->otherOrg->slug]))
            ->assertOk();
    }
}
