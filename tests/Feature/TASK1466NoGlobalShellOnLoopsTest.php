<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Ai\AiFabContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1466 (CDC 21h-23h §2.3, UX-1) — une Boucle n'affiche plus le Shell
 * global.
 *
 * ## La promesse produit
 *
 * Une Boucle POSSEDE son IA : le ChatLoop, « Demander a l'IA », les
 * connaissances, la demande d'aide. Le FAB flottant et le Shell global y
 * ouvraient une SECONDE porte vers exactement les memes actions. Deux portes
 * pour une seule piece ne sont pas une commodite : c'est un doublon, et il se
 * voit — deux surfaces IA concurrentes sur le meme ecran.
 *
 * ## Ce qui s'arrete, et ce qui ne s'arrete pas
 *
 * La frontiere posee est celle du RENDU, pas celle de la connaissance :
 *
 *  - `shouldRenderFab()` et `shouldMountShell()` repondent `false` sur un
 *    ChatLoop — rien ne se monte ;
 *  - `forRequest()` continue de CALCULER le contexte d'une page Boucle, parce
 *    que le Shell (depuis une autre page) et `AiSelfKnowledge` le lisent. Le
 *    detruire aurait supprime une autorite pour resoudre un probleme
 *    d'affichage.
 *
 * C'est pour cela que ce test mesure les deux : l'absence a l'ecran ET la
 * survie du contexte. Un correctif qui aurait vide `forRequest()` passerait la
 * premiere moitie et echouerait la seconde.
 *
 * ## Ce qui ne doit surtout pas disparaitre
 *
 * L'IA NATIVE de la Boucle. Supprimer le doublon en supprimant les deux
 * surfaces serait une regression, pas une correction — d'ou la mesure
 * explicite de l'ecoute `bp-open-ask-ai` et du formulaire canonique.
 */
class TASK1466NoGlobalShellOnLoopsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-ux1',
            'name' => 'Org UX1',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1466-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ada',
            'name' => 'UX1',
        ]);

        app()->instance('current_organization', $this->organization);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle UX1');

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier UX1',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Sur une Boucle : aucune surface IA globale
    // =====================================================================

    /** Les DEUX formes de la route (prefixee et non prefixee) sont la MEME page. */
    public function test_no_global_shell_and_no_fab_on_either_form_of_the_loop_route(): void
    {
        $urls = [
            route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]),
            route('loops.show', ['loop' => $this->loop->id]),
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($this->member)->get($url);

            $response->assertOk()
                // Le panneau du Shell, son en-tete et son marqueur de lieu.
                ->assertDontSee('data-ai-shell-panel', false)
                ->assertDontSee('id="ai-shell-title"', false)
                ->assertDontSee('data-ai-shell-here', false)
                // Le FAB : sa racine, sa page, ses actions, son entree Shell.
                ->assertDontSee('data-ai-fab', false)
                ->assertDontSee('data-ai-fab-page=', false)
                ->assertDontSee('data-ai-fab-action=', false)
                ->assertDontSee('bp-open-ai-shell', false);
        }
    }

    // =====================================================================
    // B. L'IA NATIVE de la Boucle est intacte
    // =====================================================================

    public function test_the_native_loop_ai_is_untouched(): void
    {
        $html = $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->getContent();

        // Le formulaire canonique « Demander a l'IA » et son ecoute : ils
        // n'ont jamais appartenu au FAB, et ils restent.
        $this->assertStringContainsString('@bp-open-ask-ai.window', $html);
        // `e()` et non la chaine brute : « Demander a l'IA » porte une
        // apostrophe, que Blade rend en `&#039;`. Une assertion sur la forme
        // brute serait verte pour la mauvaise raison, ou rouge a tort.
        $this->assertStringContainsString(e(__('ai.fab_action_loop_ask')), $html);
    }

    // =====================================================================
    // C. Ailleurs, rien n'a bouge
    // =====================================================================

    public function test_the_shell_and_the_fab_are_still_there_on_a_governed_page(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.dossiers.show', ['organization' => $this->organization->slug, 'dossier' => $this->dossier->id]))
            ->assertOk()
            ->assertSee('data-ai-shell-panel', false)
            ->assertSee('data-ai-fab-page="dossier"', false);

        $this->actingAs($this->member)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSee('data-ai-shell-panel', false)
            ->assertSee('data-ai-fab', false);
    }

    // =====================================================================
    // D. Le CONTEXTE d'une Boucle survit — seul le rendu s'arrete
    // =====================================================================

    /**
     * La regle n'est pas « la Boucle n'a plus de contexte IA » : c'est « la
     * Boucle n'affiche pas une seconde surface ». Le Shell ouvert AILLEURS et
     * `AiSelfKnowledge` lisent toujours ce contexte ; le supprimer aurait
     * casse ces deux lecteurs sans qu'aucun ecran ne le montre.
     */
    public function test_the_loop_context_is_still_computed_even_though_nothing_renders(): void
    {
        $url = route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]);
        $fab = app(AiFabContext::class);

        $this->actingAs($this->member)->get($url)->assertOk();

        $context = $fab->forRequest(app('request'), $this->member);

        $this->assertNotNull($context, 'le contexte de la page Boucle existe toujours');
        $this->assertSame('loop', $context['page']);
        $this->assertContains(
            AiFabContext::ACTION_LOOP_ASK,
            array_column($context['actions'], 'key'),
            'les actions de la Boucle restent calculees par leur autorite',
        );

        // Et l'autorite publique que lisent le Shell et AiSelfKnowledge.
        $this->assertNotEmpty($fab->loopActions($this->loop, $this->member));
    }

    // =====================================================================
    // E. La decision est prise sur le NOM DE ROUTE, pas sur l'URL
    // =====================================================================

    public function test_the_decision_is_taken_on_the_route_name(): void
    {
        $fab = app(AiFabContext::class);

        foreach (AiFabContext::LOOP_SURFACE_ROUTES as $routeName) {
            $request = Request::create('/peu-importe', 'GET');
            $request->setRouteResolver(fn () => (new \Illuminate\Routing\Route('GET', '/peu-importe', []))->name($routeName));

            $this->assertTrue($fab->isLoopSurface($request), $routeName.' est une surface Boucle');
            $this->assertFalse($fab->shouldRenderFab($request, $this->member));
            $this->assertFalse($fab->shouldMountShell($request, $this->member));
        }

        // Une route dont le NOM contient « loops » sans etre le ChatLoop n'est
        // pas concernee : la liste est exacte, jamais un `str_contains`.
        $index = Request::create('/peu-importe', 'GET');
        $index->setRouteResolver(fn () => (new \Illuminate\Routing\Route('GET', '/peu-importe', []))->name('organization.loops.index'));

        $this->assertFalse($fab->isLoopSurface($index));
    }
}
