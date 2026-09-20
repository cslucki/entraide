<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1606 — un refus cross-tenant rend 404, et propose une sortie UTILE.
 *
 * ## Le refus lui-meme n'est pas en cause
 *
 * Le 404 est CORRECT et le reste : un 403 dirait « il y a quelque chose ici,
 * mais pas pour vous », ce qui divulguerait deja l'existence de la ressource.
 * Cette TASK ne touche a aucune autorisation.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Sessions HTTP reelles sur `https://test.laravel`, base `bouclepro`.
 * `main` porte le nom « BouclePro » ; `launchpals` porte « LaunchPals ».
 *
 * | scenario | sortie AVANT | verdict |
 * |---|---|---|
 * | invite, URL inexistante | `/org/main` | correct — `main` est l'Organization par defaut |
 * | invite sous `/org/launchpals/…` | `/org/launchpals` | correct — l'URL exprime le contexte |
 * | **membre launchpals -> `/org/main/loops` refusee** | **`/org/main`** | **DEFAUT** — l'Organization ETRANGERE |
 *
 * Sur ce dernier cas, le titre annoncait « · BouclePro » — le nom de `main` —
 * a un membre de LaunchPals, et **0** occurrence de « LaunchPals ».
 *
 * ## La racine
 *
 * `errors/404.blade.php` deduisait son Organization de l'URL REFUSEE :
 *
 * ```php
 * $errorOrg = $currentOrganization ?? null;            // lie depuis l'URL refusee
 * if (! $errorOrg && request()->segment(1) === 'org' && request()->segment(2)) {
 *     $errorOrg = Organization::where('slug', request()->segment(2))->first();
 * }
 * ```
 *
 * Les deux branches lisent la ressource etrangere. Le correctif prefere, pour
 * un utilisateur AUTHENTIFIE, le seul contexte sur : **son Organization a
 * lui**. Aucune requete n'interroge plus la ressource refusee dans ce cas —
 * son existence n'est jamais un signal.
 *
 * Pour un INVITE, rien ne change : il n'a pas d'Organization legitime, et
 * l'URL qu'il a demandee reste l'expression de son contexte.
 */
class TASK1606CrossTenantNotFoundTest extends TestCase
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
            'slug' => 'org-1606-defaut',
            'name' => 'Org 1606 Defaut',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
            'created_at' => now()->subYear(),
        ]);

        $this->otherOrg = Organization::factory()->create([
            'slug' => 'org-1606-autre',
            'name' => 'Org 1606 Autre',
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

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    /** Le lien de sortie du 404 — le seul `<a>` de cette page. */
    private function sortie(string $html): ?string
    {
        preg_match('#<a\s+href="([^"]+)"[^>]*>\s*<svg#s', $html, $m);

        return $m[1] ?? null;
    }

    // =====================================================================
    // A. Le refus cross-tenant propose la sortie de l'utilisateur
    // =====================================================================

    public function test_a_a_refused_member_is_offered_their_own_organization(): void
    {
        $this->oublierOrganisation();

        $reponse = $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]));

        $reponse->assertNotFound();

        $this->assertSame(
            route('organization.home', ['organization' => $this->otherOrg->slug]),
            $this->sortie($reponse->getContent()),
            'la sortie du 404 mene a l\'Organization ETRANGERE de l\'URL refusee'
        );
    }

    /** Le meme contrat dans l'autre sens. */
    public function test_a_the_default_organization_member_gets_the_same_contract(): void
    {
        $this->oublierOrganisation();

        $reponse = $this->actingAs($this->defaultMember)
            ->get(route('organization.loops.index', ['organization' => $this->otherOrg->slug]));

        $reponse->assertNotFound();

        $this->assertSame(
            route('organization.home', ['organization' => $this->defaultOrg->slug]),
            $this->sortie($reponse->getContent())
        );
    }

    /**
     * AUCUNE divulgation sur la ressource etrangere : ni son slug, ni son nom.
     *
     * C'est la garde qui compte le plus ici — elle vaut pour le refus lui-meme
     * autant que pour la sortie.
     */
    public function test_a_nothing_about_the_foreign_organization_is_disclosed(): void
    {
        $this->oublierOrganisation();

        $html = $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound()
            ->getContent();

        $this->assertStringNotContainsString($this->defaultOrg->slug, $html,
            'le slug de l\'Organization etrangere fuit dans la page 404');
        $this->assertStringNotContainsString($this->defaultOrg->name, $html,
            'le NOM de l\'Organization etrangere fuit dans la page 404');
    }

    /** Le refus reste un 404 : on ne le transforme pas en 403. */
    public function test_a_the_refusal_is_still_a_404(): void
    {
        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertStatus(404);
    }

    // =====================================================================
    // B. L'invite ne change pas — on ne lui invente aucune Organization
    // =====================================================================

    public function test_b_a_guest_on_an_unknown_url_keeps_the_current_behaviour(): void
    {
        $this->oublierOrganisation();

        $reponse = $this->get('/page-inexistante-1606');

        $reponse->assertNotFound();
        $this->assertNotNull($this->sortie($reponse->getContent()),
            'la page 404 publique a perdu sa sortie');
    }

    /** Sous `/org/{slug}`, l'URL demandee reste l'expression du contexte de l'invite. */
    public function test_b_a_guest_under_an_organization_prefix_stays_there(): void
    {
        $this->oublierOrganisation();

        $reponse = $this->get('/org/'.$this->otherOrg->slug.'/page-inexistante-1606');

        $reponse->assertNotFound();
        $this->assertSame(
            route('organization.home', ['organization' => $this->otherOrg->slug]),
            $this->sortie($reponse->getContent()),
            'la sortie de l\'invite ne suit plus l\'Organization qu\'il a demandee'
        );
    }

    // =====================================================================
    // C. Les acquis de TASK-1603 ne bougent pas
    // =====================================================================

    /** Aucun moteur de recherche, dans aucun des deux cas. */
    public function test_c_no_search_engine_on_either_kind_of_404(): void
    {
        $this->oublierOrganisation();
        $public = $this->get('/page-inexistante-1606')->getContent();

        $this->oublierOrganisation();
        $tenant = $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->getContent();

        foreach (['404 public' => $public, '404 cross-tenant' => $tenant] as $contexte => $html) {
            $this->assertStringNotContainsString('role="search"', $html, $contexte);
            $this->assertStringNotContainsString('name="q"', $html, $contexte);
            $this->assertDoesNotMatchRegularExpression('#action="[^"]*/search"#', $html, $contexte);
        }
    }

    /** L'i18n du 404 reste gouvernee par la cascade de `SetLocale`. */
    public function test_c_the_404_still_follows_the_locale_cascade(): void
    {
        $this->oublierOrganisation();
        $fr = $this->get('/page-inexistante-1606', ['Accept-Language' => 'fr-FR,fr;q=0.9']);
        $fr->assertNotFound();
        $fr->assertSee('lang="fr"', false);
        $fr->assertSee(__('errors.404_message', [], 'fr'));

        $this->oublierOrganisation();
        $en = $this->get('/page-inexistante-1606', ['Accept-Language' => 'en-US,en;q=0.9']);
        $en->assertNotFound();
        $en->assertSee('lang="en"', false);
        $en->assertSee(__('errors.404_message', [], 'en'));
    }

    /** Les verbes et la negociation JSON sont inchanges. */
    public function test_c_verbs_and_json_are_unchanged(): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verbe) {
            $this->{$verbe}('/uri-inexistante-1606')->assertNotFound();
        }

        $this->getJson('/uri-inexistante-1606')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
    }

    // =====================================================================
    // D. Les gardes tenant ne sont pas affaiblies
    // =====================================================================

    public function test_d_cross_tenant_access_is_still_refused(): void
    {
        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->defaultOrg->slug]))
            ->assertNotFound();

        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.dashboard', ['organization' => $this->defaultOrg->slug]))
            ->assertForbidden();
    }

    /** Un membre garde l'acces a SON Organization. */
    public function test_d_a_member_still_reaches_their_own_organization(): void
    {
        $this->oublierOrganisation();

        $this->actingAs($this->otherMember)
            ->get(route('organization.loops.index', ['organization' => $this->otherOrg->slug]))
            ->assertOk();
    }
}
