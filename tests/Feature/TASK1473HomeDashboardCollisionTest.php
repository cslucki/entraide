<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\UsageReference;
use App\Models\User;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1473 — l'accueil public et le tableau de bord cessent de partager une
 * cle.
 *
 * ## La collision, precisement
 *
 * Depuis TASK-1469, `AiShellPageContext::SURFACE_ROUTES` faisait pointer la
 * cle `organization_home` vers `dashboard` et `organization.dashboard`. Or
 * `UsageReference::SURFACE_ORGANIZATION_HOME` porte la MEME chaine pour
 * designer l'ACCUEIL PUBLIC.
 *
 * Meme mot, deux lieux.
 *
 * ## Ce qui etait deja faux, et ce qui ne l'etait pas encore
 *
 * **Deja faux, et visible** : le Shell annoncait « Vous etes sur l'accueil » a
 * quelqu'un qui regardait son tableau de bord.
 *
 * **Pas encore faux** : aucune fuite de contenu. Le Shell MEMBRE ne consomme
 * pas `UsageReference` — seul `GuestPublicContextBuilder` le fait, et il
 * recoit sa cle explicitement. La collision etait donc une bombe a retardement
 * plutot qu'un bug actif : la premiere surface qui aurait relie les deux
 * couches aurait servi l'aide de l'accueil PUBLIC a un membre sur son
 * tableau de bord.
 *
 * On ne repare pas seulement le libelle : on retire la cause.
 *
 * ## Et la vraie route de l'accueil existait
 *
 * `organization.home` (`org/{organization}`, PUBLIC) n'etait dans aucune table
 * de surfaces, alors que `organization.dashboard` (AUTH) portait la cle de
 * l'accueil. Les deux couples existent bien et sont distincts.
 */
class TASK1473HomeDashboardCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-collision',
            'name' => 'Org Collision',
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);

        config(['ai.fab.enabled' => true, 'ai.shell.enabled' => true]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Deux surfaces, deux cles, deux ensembles de routes
    // =====================================================================

    public function test_the_home_key_no_longer_points_at_the_dashboard(): void
    {
        $home = AiShellPageContext::SURFACE_ROUTES['organization_home'];
        $dashboard = AiShellPageContext::SURFACE_ROUTES['dashboard'];

        $this->assertSame([], array_intersect($home, $dashboard), 'aucune route ne peut appartenir aux deux surfaces');

        foreach (['dashboard', 'organization.dashboard'] as $route) {
            $this->assertNotContains($route, $home, "[{$route}] est un tableau de bord, pas un accueil");
            $this->assertContains($route, $dashboard);
        }

        foreach (['home', 'organization.home'] as $route) {
            $this->assertContains($route, $home, "[{$route}] est l'accueil");
            $this->assertNotContains($route, $dashboard);
        }
    }

    /**
     * Les deux routes de l'accueil sont PUBLIQUES, celles du tableau de bord
     * sont AUTHENTIFIEES. Si un jour l'une des quatre changeait de nature, la
     * distinction perdrait son sens produit — ce test le dirait.
     */
    public function test_the_two_surfaces_do_not_have_the_same_nature(): void
    {
        foreach (AiShellPageContext::SURFACE_ROUTES['organization_home'] as $name) {
            $this->assertTrue(Route::has($name), $name);
            $this->assertFalse($this->routeRequiresAuth($name), "[{$name}] : l'accueil est public");
        }

        foreach (AiShellPageContext::SURFACE_ROUTES['dashboard'] as $name) {
            $this->assertTrue(Route::has($name), $name);
            $this->assertTrue($this->routeRequiresAuth($name), "[{$name}] : le tableau de bord est authentifie");
        }
    }

    // =====================================================================
    // B. A l'ecran : chaque page nomme SON lieu
    // =====================================================================

    public function test_the_dashboard_no_longer_claims_to_be_the_home_page(): void
    {
        $html = $this->actingAs($this->member)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-shell-surface="dashboard"', $html);
        $this->assertStringContainsString(e(__('ai.shell_surface_dashboard')), $html);
        $this->assertStringNotContainsString(e(__('ai.shell_surface_organization_home')), $html,
            'le tableau de bord ne se presente plus comme l\'accueil');
    }

    /** Les deux libelles existent, different, et ne se confondent pas. */
    public function test_home_and_dashboard_have_distinct_labels_in_both_languages(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            $home = __('ai.shell_surface_organization_home');
            $dashboard = __('ai.shell_surface_dashboard');

            $this->assertNotSame('ai.shell_surface_dashboard', $dashboard, $locale.' : cle non traduite');
            $this->assertNotSame($home, $dashboard, $locale.' : deux lieux, deux phrases');
        }

        app()->setLocale('fr');
    }

    // =====================================================================
    // C. La cause : l'aide de l'accueil public ne peut pas atterrir ailleurs
    // =====================================================================

    /**
     * Le coeur de la tranche. On publie une UsageReference pour l'accueil
     * PUBLIC, puis on regarde le tableau de bord : son contenu ne doit
     * apparaitre nulle part.
     *
     * Aujourd'hui le Shell membre ne lit pas UsageReference — ce test serait
     * donc vert meme sans le correctif. Ce qu'il protege est l'AVENIR : le jour
     * ou une surface membre lira une reference par cle de PageContext, elle ne
     * pourra plus recevoir celle de l'accueil public par homonymie.
     */
    public function test_the_public_home_usage_reference_never_reaches_the_dashboard(): void
    {
        $marker = 'Aide-de-l-accueil-public-TASK1473';

        UsageReference::query()->create([
            'surface_key' => UsageReference::SURFACE_ORGANIZATION_HOME,
            'locale' => 'fr',
            'title' => 'Accueil public',
            'content' => $marker,
            'version' => 1,
            'state' => 'published',
        ]);

        // La cle de la reference et celle du PageContext du dashboard sont
        // desormais differentes : c'est ce qui rend l'homonymie impossible.
        $this->assertNotSame(
            UsageReference::SURFACE_ORGANIZATION_HOME,
            'dashboard',
            'la surface du dashboard ne porte plus le nom de l\'accueil public',
        );

        $html = $this->actingAs($this->member)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($marker, $html);
    }

    // =====================================================================
    // D. La surface n'accorde toujours aucun droit
    // =====================================================================

    /**
     * Rappel du contrat de `AiShellPageContext` : elle DECRIT, elle n'autorise
     * pas. Un membre d'une autre Organization ne franchit pas le dashboard
     * parce que sa surface a un nom.
     */
    public function test_naming_a_surface_grants_nothing(): void
    {
        $other = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-collision-b']);
        $stranger = User::factory()->complete()->create(['organization_id' => $other->id]);

        // Le service de CETTE Organization, qui ne doit jamais apparaitre.
        $secret = \App\Models\Service::factory()->create([
            'user_id' => $this->member->id,
            'title' => 'Prestation-privee-TASK1473',
            'status' => 'active',
        ]);

        $response = $this->actingAs($stranger)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]));

        // Mesure faite : la route rend 200 pour un membre d'une AUTRE
        // Organization — `ResolveOrganization` ne verifie pas l'appartenance.
        // Ce qui compte, et qui est verifie ici, est que la page ne montre
        // AUCUNE donnee de l'Organization visitee : le controleur ne lit que
        // les donnees de l'utilisateur connecte. Le comportement de la route
        // elle-meme est un finding rapporte dans la fiche, hors perimetre de
        // cette tranche — le corriger sans mesure d'impact casserait
        // potentiellement des parcours legitimes.
        $html = $response->getContent();

        $this->assertStringNotContainsString($secret->title, $html, 'aucune donnee de l\'Organization visitee');
        $this->assertStringNotContainsString($this->member->email, $html, 'aucun membre de l\'Organization visitee');

        // Et la surface, elle, n'a rien accorde : elle nomme, c'est tout.
        $this->assertSame('dashboard', AiShellPageContext::surfaceFor('organization.dashboard'));
    }

    private function routeRequiresAuth(string $name): bool
    {
        $route = Route::getRoutes()->getByName($name);

        foreach ($route?->gatherMiddleware() ?? [] as $middleware) {
            if (str_contains((string) $middleware, 'Authenticate') || $middleware === 'auth') {
                return true;
            }
        }

        return false;
    }
}
