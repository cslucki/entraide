<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1605 — dans `/org/{organization}/*`, aucun element de navigation VISIBLE
 * ne doit faire sortir implicitement de l'Organization.
 *
 * ## A — le logo
 *
 * Mesure, sessions HTTP reelles sur `https://test.laravel` / base `bouclepro` :
 *
 * | point de marque | href AVANT | verdict |
 * |---|---|---|
 * | `organization/artscilab-hero:52` | `url('/')` | **defaut** — la racine retombe sur `main` |
 * | `components/mobile-topbar:147` | `url('/')` | **defaut**, et il vaut pour TOUTE page en mobile |
 * | `layouts/guest:51` | scope sauf Organization par defaut | **incomplet** — l'arbitrage demande `/org/main` aussi |
 * | `layouts/navigation:10` | se branche sur le LIAGE, pas sur l'URL | **incomplet** |
 * | `components/app-side-nav:196` | deja borne par l'URL | correct |
 * | `organization/home:17` | deja `organization.home` | correct |
 *
 * Le defaut de `mobile-topbar` n'avait pas ete vu en TASK-1604 : la recette y
 * portait sur les liens de pied de page, pas sur la barre mobile.
 *
 * ## B — « Un bug ? »
 *
 * **Le mecanisme existe deja et il est deja borne.** `organization.bug-reports.index`
 * (`GET /org/{organization}/bugs`, public) et `organization.bug-reports.store`
 * (`POST`, authentifie + throttle) sont en place, avec `BugReportController`.
 * Rien a inventer cote backend.
 *
 * Ce qui est en cause est la FORME : dans `partials/footer`, « Un bug ? » est un
 * `<button>` qui bascule un popup Alpine (`x-data="{ bugOpen: false }"`), lequel
 * embarque le formulaire. Arbitrage MASTER : **pas de popup** — le lien mene a
 * la vraie page, deja bornee.
 *
 * `organization/hero-v2` (gabarit de `main`) liait deja la page : c'est
 * `partials/footer` — utilise par `layouts/guest`, donc par `/org/{slug}/login`
 * et `/org/{slug}/register` — qui ouvre le popup.
 */
class TASK1605OrganizationNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrg;

    private Organization $otherOrg;

    private User $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultOrg = Organization::factory()->create([
            'slug' => 'org-1605-defaut',
            'name' => 'Org 1605 Defaut',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now()->subYear(),
        ]);

        $this->otherOrg = Organization::factory()->create([
            'slug' => 'org-1605-autre',
            'name' => 'Org 1605 Autre',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now(),
        ]);

        $this->otherMember = User::factory()->complete()->create([
            'organization_id' => $this->otherOrg->id,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * `is_default` est une colonne GLOBALE : la reposer a faux evite qu'un test
     * qui l'a activee empoisonne la suite — meme precaution que `T1392`.
     */
    protected function tearDown(): void
    {
        Organization::query()->where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    /** Aucun lien vers la RACINE NUE : c'est elle qui retombe sur l'Organization par defaut. */
    private function assertAucunLienVersLaRacine(string $html, string $contexte): void
    {
        $this->assertStringNotContainsString('href="'.url('/').'"', $html,
            "{$contexte} : un element de navigation pointe sur la racine nue");
        $this->assertStringNotContainsString('href="/"', $html,
            "{$contexte} : un element de navigation pointe sur la racine nue");
    }

    // =====================================================================
    // A. Le logo reste dans l'Organization
    // =====================================================================

    public function test_a_the_guest_layout_brand_points_to_the_organization(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.login', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.home', ['organization' => $this->otherOrg->slug]).'"',
            $html,
            'la marque de la page de connexion ne mene pas a l\'accueil de l\'Organization'
        );
        $this->assertAucunLienVersLaRacine($html, 'login borne');
    }

    public function test_a_the_register_page_brand_points_to_the_organization(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.register', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.home', ['organization' => $this->otherOrg->slug]).'"',
            $html
        );
        $this->assertAucunLienVersLaRacine($html, 'register borne');
    }

    /**
     * L'Organization PAR DEFAUT est bornee elle aussi — arbitrage MASTER :
     * « main -> /org/main ». C'est la ou `layouts/guest` s'arretait.
     */
    public function test_a_the_default_organization_brand_is_scoped_too(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.login', ['organization' => $this->defaultOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.home', ['organization' => $this->defaultOrg->slug]).'"',
            $html,
            'l\'Organization par defaut n\'est pas bornee alors que l\'arbitrage MASTER le demande'
        );
        $this->assertAucunLienVersLaRacine($html, 'login de l\'Organization par defaut');
    }

    /**
     * La barre MOBILE porte le meme defaut — mais seulement la ou elle affiche
     * la marque, et c'est une nuance mesuree, pas supposee.
     *
     * Dans `components/mobile-topbar`, le bloc de marque est garde par
     * `@if(request()->routeIs('login', 'organization.login'))` : ailleurs, la
     * barre affiche un bouton « retour », pas le logo. Une premiere version de
     * ce test visait `/org/{slug}/dashboard` et passait AU VERT sans rien
     * mesurer — la marque n'y est tout simplement pas rendue.
     *
     * La surface reelle est donc la page de connexion, ou `url('/')` renvoyait
     * vers la racine nue, donc vers l'Organization par defaut.
     */
    public function test_a_the_mobile_brand_on_the_login_page_is_scoped(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.login', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('organization.home', ['organization' => $this->otherOrg->slug]), '#').'"[^>]*aria-label="[^"]*'.preg_quote($this->otherOrg->name, '#').'"#',
            $html,
            'la marque de la barre mobile ne mene pas a l\'accueil de l\'Organization'
        );
    }

    /**
     * LE DEFAUT D'ORIGINE — celui qui a motive la TASK.
     *
     * `organization/artscilab-hero` est le gabarit public de `launchpals` :
     * son logo pointait sur `url('/')`, donc sur l'Organization par defaut.
     *
     * Ce test n'existait pas dans la premiere version du fichier. Un sabotage
     * — rendre `url('/')` a ce gabarit — laissait les 14 tests VERTS : le
     * defaut nomme par l'arbitrage n'etait tenu par AUCUN test. Les autres
     * surfaces le masquaient.
     */
    public function test_a_the_public_organization_template_brand_is_scoped(): void
    {
        $this->otherOrg->forceFill(['homepage_template' => 'artscilab_hero'])->save();

        $this->oublierOrganisation();

        $html = $this->get(route('organization.home', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.home', ['organization' => $this->otherOrg->slug]).'"><img class="logo"',
            $html,
            'le logo du gabarit public mene encore hors de l\'Organization'
        );
        $this->assertAucunLienVersLaRacine($html, 'gabarit public d\'Organization');
    }

    /**
     * La marque BUREAU du gabarit invite, mesuree pour elle-meme.
     *
     * Sabotage revelateur : remettre l'ancienne regle de `layouts/guest` ne
     * faisait rien rougir, parce que la marque MOBILE de la meme page portait
     * deja le bon lien et satisfaisait l'assertion. Deux elements distincts
     * demandent deux mesures distinctes.
     */
    public function test_a_the_desktop_guest_brand_is_scoped_on_its_own(): void
    {
        // L'ancienne regle de `layouts/guest` testait la COLONNE `is_default`.
        // La poser ici n'est pas un detail : sans elle, `$this->defaultOrg` est
        // « par defaut » seulement par l'ordre de creation — ce qui suffit a
        // `resolveDefaultOrganization()` mais PAS a cette regle. Une premiere
        // version de ce test l'omettait et restait verte sous sabotage : elle
        // ne mesurait pas ce qu'elle annoncait.
        $this->defaultOrg->forceFill(['is_default' => true])->save();

        $this->oublierOrganisation();

        $html = $this->get(route('organization.login', ['organization' => $this->defaultOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.home', ['organization' => $this->defaultOrg->slug]).'" class="flex items-center gap-3 group"',
            $html,
            'la marque BUREAU de la page de connexion n\'est pas bornee (l\'Organization par defaut etait exclue)'
        );
    }

    /** Une surface REELLEMENT globale n'est pas bornee. */
    public function test_a_a_global_surface_keeps_its_global_brand(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'href="'.route('organization.home', ['organization' => $this->otherOrg->slug]).'"',
            $html,
            'une surface globale a ete bornee sur une Organization que le visiteur n\'a pas demandee'
        );
    }

    // =====================================================================
    // B. « Un bug ? » mene a une vraie page, bornee
    // =====================================================================

    /** Le mecanisme backend EXISTE deja : on ne le reinvente pas. */
    public function test_b_the_existing_scoped_mechanism_is_reused(): void
    {
        $this->assertTrue(Route::has('organization.bug-reports.index'));
        $this->assertTrue(Route::has('organization.bug-reports.store'));
    }

    /**
     * Plus de popup : « Un bug ? » est un LIEN vers la page bornee.
     *
     * La garde porte sur le declencheur Alpine du pied de page — `bugOpen` —,
     * pas sur Alpine en general : la page de signalement garde sa propre
     * revelation progressive, qui est une affordance de PAGE, pas un popup
     * superpose au pied de page.
     */
    public function test_b_the_footer_offers_a_link_not_a_popup(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.login', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bugOpen', $html,
            'le pied de page ouvre encore un popup Alpine au lieu de mener a la page');

        $this->assertStringContainsString(
            'href="'.route('organization.bug-reports.index', ['organization' => $this->otherOrg->slug]).'"',
            $html,
            'le pied de page ne mene pas a la page de signalement bornee'
        );
    }

    public function test_b_the_register_page_links_to_the_scoped_bug_report(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.register', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bugOpen', $html);
        $this->assertStringContainsString(
            'href="'.route('organization.bug-reports.index', ['organization' => $this->otherOrg->slug]).'"',
            $html
        );
    }

    /** La page bornee reste dans l'Organization, pour un invite. */
    public function test_b_the_scoped_page_keeps_the_organization_for_a_guest(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.bug-reports.index', [
            'organization' => $this->otherOrg->slug,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('/org/'.$this->otherOrg->slug, $html,
            'la page de signalement bornee ne porte aucune navigation de l\'Organization');
        $this->assertAucunLienVersLaRacine($html, 'page de signalement bornee');
    }

    /** Et pour un membre. */
    public function test_b_the_scoped_page_keeps_the_organization_for_a_member(): void
    {
        $this->oublierOrganisation();

        $html = $this->actingAs($this->otherMember)
            ->get(route('organization.bug-reports.index', ['organization' => $this->otherOrg->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'action="'.route('organization.bug-reports.store', ['organization' => $this->otherOrg->slug]).'"',
            $html,
            'le formulaire de la page bornee ne poste pas dans l\'Organization'
        );
    }

    /** Les droits existants ne bougent pas : le POST reste reserve aux authentifies. */
    public function test_b_posting_still_requires_authentication(): void
    {
        $this->oublierOrganisation();

        $this->post(route('organization.bug-reports.store', ['organization' => $this->otherOrg->slug]), [
            'reason' => 'Navigation',
            'details' => 'Tentative anonyme TASK-1605',
        ])->assertRedirect(route('organization.login', ['organization' => $this->otherOrg->slug], false));

        $this->assertDatabaseMissing('bug_reports', ['details' => 'Tentative anonyme TASK-1605']);
    }

    // =====================================================================
    // C. Rien d'autre ne bouge
    // =====================================================================

    /** La route globale de signalement continue de repondre. */
    public function test_c_the_global_bug_report_page_still_answers(): void
    {
        $this->oublierOrganisation();
        $this->get(route('bug-reports.index'))->assertOk();
    }

    /** La garde tenant n'est pas affaiblie. */
    public function test_c_cross_tenant_access_is_still_refused(): void
    {
        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound();
    }

    /** Aucune information inter-tenant sur la page bornee. */
    public function test_c_the_scoped_page_never_names_another_organization(): void
    {
        $this->oublierOrganisation();

        $html = $this->get(route('organization.bug-reports.index', [
            'organization' => $this->otherOrg->slug,
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('/org/'.$this->defaultOrg->slug, $html,
            'la page bornee expose une AUTRE Organization');
    }
}
