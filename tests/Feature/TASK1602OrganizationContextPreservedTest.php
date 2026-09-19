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
 * TASK-1602 — une fois dans une Organization, la navigation ne doit plus faire
 * basculer implicitement vers l'Organization par defaut.
 *
 * ## Les quatre pertes de contexte, reproduites avant tout code
 *
 * Sessions HTTP reelles sur `https://test.laravel`, base `bouclepro`, compte
 * `launchpals.member1@bouclepro.test` :
 *
 * | cas | mesure |
 * |---|---|
 * | **A** bouton retour du shell de Boucle | `href="https://test.laravel"` — la RACINE, qui retombe sur `main` |
 * | **B** bascule FR/EN depuis `/org/launchpals` | `POST /locale/en` -> 302 vers `https://test.laravel` -> `/org/main` |
 * | **C** mentions legales en INVITE depuis launchpals | 7 occurrences « BouclePro », **0** « LaunchPals » |
 * | **D** invite sur `/org/launchpals/dashboard` | 302 vers **`/login`**, pas `/org/launchpals/login` |
 *
 * ## Une seule racine, quatre replis
 *
 * Le code possede deja tout ce qu'il faut — `canonicalHome()`,
 * `organization.login`, la famille `organization.*`. Ce sont les CHEMINS DE
 * REPLI qui ignorent l'Organization courante :
 *
 * - A : `loops/show.blade.php` appelle `route('home')` au lieu du helper ;
 * - B : `LocaleController` retombe sur `redirect('/')` quand `redirect_to` est
 *   absent — et les selecteurs des gabarits d'Organization ne l'envoient pas ;
 * - C : `/mentions-legales` est dans `$platformGlobalExact`, donc aucune
 *   Organization n'est liee ;
 * - D : le repli invite de Laravel pointe sur `route('login')`, alors que
 *   `organization.login` EXISTE (`routes/web.php:872`).
 *
 * Aucun refactor du systeme tenant : on branche les replis sur ce qui existe.
 */
class TASK1602OrganizationContextPreservedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrg;

    private Organization $otherOrg;

    private User $defaultMember;

    private User $otherMember;

    private Loop $otherLoop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultOrg = Organization::factory()->create([
            'slug' => 'org-1602-defaut',
            'name' => 'Org 1602 Defaut',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now()->subYear(),
        ]);

        $this->otherOrg = Organization::factory()->create([
            'slug' => 'org-1602-autre',
            'name' => 'Org 1602 Autre',
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

        $this->otherLoop = Loop::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'created_by' => $this->otherMember->id,
        ]);

        LoopMember::query()->create([
            'loop_id' => $this->otherLoop->id,
            'user_id' => $this->otherMember->id,
            'organization_id' => $this->otherOrg->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // `launchpals` est MONO-boucle (`loop_mode = 'mono'`, mesure en base) —
        // et c'est precisement ce mode qui fait apparaitre le bouton « retour »
        // du shell, celui qui pointait sur la racine. Sans cette ligne, le test
        // ne reproduit rien.
        $this->otherOrg->forceFill([
            'loop_mode' => 'mono',
            'primary_loop_id' => $this->otherLoop->id,
        ])->save();

        Http::preventStrayRequests();
        Http::fake();
    }

    private function forgetOrganization(): void
    {
        app()->forgetInstance('current_organization');
    }

    // =====================================================================
    // A. La navigation d'une Boucle ne retombe jamais sur la racine
    // =====================================================================

    public function test_a_the_loop_workspace_back_link_never_points_to_the_bare_root(): void
    {
        $this->forgetOrganization();

        $html = $this->actingAs($this->otherMember)
            ->get(route('organization.loops.show', [
                'organization' => $this->otherOrg->slug,
                'loop' => $this->otherLoop->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'href="'.url('/').'"',
            $html,
            'le shell de Boucle pointe encore sur la RACINE, qui retombe sur l\'Organization par defaut'
        );
    }

    // =====================================================================
    // B. La bascule FR/EN conserve l'Organization
    // =====================================================================

    /** Sans `redirect_to` — c'est le cas des selecteurs des gabarits d'Organization. */
    public function test_b_locale_switch_without_redirect_keeps_the_organization_for_a_member(): void
    {
        $this->forgetOrganization();

        $this->actingAs($this->otherMember)
            ->post(route('locale.switch', ['locale' => 'en']))
            ->assertRedirectContains('/org/'.$this->otherOrg->slug);
    }

    /** Et pour un invite, dont l'Organization vient de l'URL visitee. */
    public function test_b_locale_switch_keeps_the_organization_for_a_guest(): void
    {
        $this->forgetOrganization();
        $this->get(route('organization.home', ['organization' => $this->otherOrg->slug]))->assertOk();

        $this->forgetOrganization();

        $this->from(route('organization.home', ['organization' => $this->otherOrg->slug]))
            ->post(route('locale.switch', ['locale' => 'en']))
            ->assertRedirectContains('/org/'.$this->otherOrg->slug);
    }

    /** Un membre de l'Organization par defaut garde son comportement. */
    public function test_b_locale_switch_does_not_change_the_default_organization_behaviour(): void
    {
        $this->forgetOrganization();

        $response = $this->actingAs($this->defaultMember)
            ->post(route('locale.switch', ['locale' => 'en']));

        $this->assertStringNotContainsString(
            '/org/'.$this->otherOrg->slug,
            (string) $response->headers->get('Location'),
            'un membre de l\'Organization par defaut a ete envoye chez un autre tenant'
        );
    }

    // =====================================================================
    // C. Les mentions legales gardent le contexte, sans dupliquer le contenu
    // =====================================================================

    public function test_c_legal_notice_has_an_organization_scoped_route(): void
    {
        $this->assertTrue(
            Route::has('organization.mentions-legales'),
            'aucune route canonique bornee pour les mentions legales'
        );
    }

    /**
     * Le CONTENU reste unique — la route bornee sert la MEME vue. Ce qui change,
     * et ce que ce test mesure, c'est le CONTEXTE : la navigation.
     *
     * Mesure : la route globale rend **0** lien `/org/{slug}`, la route bornee
     * en rend **15**. Le nom de l'Organization, lui, n'apparait dans aucune des
     * deux — cette page n'affiche pas de marque textuelle pour un invite. On
     * n'assied donc pas le test sur un nom qui n'y est pas.
     */
    public function test_c_legal_notice_keeps_the_organization_context_for_a_guest(): void
    {
        $this->forgetOrganization();

        $borne = $this->get(route('organization.mentions-legales', [
            'organization' => $this->otherOrg->slug,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString(
            '/org/'.$this->otherOrg->slug,
            $borne,
            'les mentions legales bornees ne portent aucune navigation de l\'Organization'
        );

        $this->forgetOrganization();

        $global = $this->get(route('mentions-legales'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            '/org/'.$this->otherOrg->slug,
            $global,
            'la route GLOBALE fuit vers une Organization : le contenu doit rester neutre'
        );
    }

    /** Le contenu n'est pas duplique : les deux routes servent la meme vue. */
    public function test_c_legal_notice_content_is_not_duplicated(): void
    {
        $this->assertSame(
            'mentions-legales',
            Route::getRoutes()->getByName('organization.mentions-legales')->defaults['view'] ?? null,
            'la route bornee ne sert pas la meme vue que la route globale'
        );
    }

    // =====================================================================
    // D. Le login declenche depuis une Organization reste borne
    // =====================================================================

    public function test_d_a_guest_on_an_organization_page_is_sent_to_the_organization_login(): void
    {
        $this->forgetOrganization();

        $this->get(route('organization.dashboard', ['organization' => $this->otherOrg->slug]))
            ->assertRedirect(route('organization.login', [
                'organization' => $this->otherOrg->slug,
            ], false));
    }

    /** Le login universel reste la porte des parcours reellement globaux. */
    public function test_d_a_guest_on_a_global_page_still_uses_the_universal_login(): void
    {
        $this->forgetOrganization();

        $this->get('/dashboard')->assertRedirect(route('login', [], false));
    }

    // =====================================================================
    // E. Les gardes tenant ne bougent pas
    // =====================================================================

    public function test_e_cross_tenant_access_is_still_refused(): void
    {
        $this->forgetOrganization();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound();
    }

    public function test_e_a_member_of_the_default_organization_keeps_the_short_url(): void
    {
        $this->forgetOrganization();

        $this->actingAs($this->defaultMember)->get('/loops')->assertOk();
    }
}
