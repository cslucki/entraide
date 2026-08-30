<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1322 (Core-2) — Degradation propre du ChatLoop quand l'IA n'est pas
 * disponible.
 *
 * L'exigence (arbitrage Cyril 28/08, ProductSpec T074.2 §3.1/§5.14/§8.3) : si
 * l'IA n'est pas disponible, degradation propre, aucune publication
 * automatique, et l'utilisateur doit pouvoir POURSUIVRE LE PARCOURS
 * MANUELLEMENT. L'Entree A (« Qui peut m'aider ? ») bloquait avec une erreur
 * quand `AiConfig::clarification_enabled` etait OFF — une impasse, pas une
 * degradation.
 */
class TASK1322ChatLoopSansIaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $nonMember;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle principale');

        app()->instance('current_organization', $this->organization);

        // Etat par defaut de cette suite : les DEUX gates OFF — le runtime
        // reel par defaut. Chaque test active explicitement ce dont il a
        // besoin ; jamais d'activation implicite.
        AiConfig::set('clarification_enabled', false);
        config(['ai.clarify.enabled' => false]);

        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Gates 1 et 2 ON + provider configure, pour les tests qui exercent une
     * indisponibilite EN AVAL des gates (echec provider, budget, prompt).
     */
    private function enableAiPath(): void
    {
        AiConfig::set('clarification_enabled', true);
        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 2)
            ->update([
                'prompt_text' => 'Reformule sans inventer et recopie uniquement les identifiants fournis.',
                'is_active' => true,
            ]);
        config([
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function analyze(string $intention = 'Je cherche des retours pour structurer une demande de mentorat')
    {
        return $this->actingAs($this->member)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => $intention,
            ]);
    }

    // =====================================================================
    // A. Gate 1 OFF — plus d'impasse : le parcours continue manuellement
    // =====================================================================

    public function test_gate_one_off_degrades_to_the_canonical_form_instead_of_blocking(): void
    {
        $response = $this->analyze();

        // Plus d'erreur-impasse : cap sur le formulaire canonique, avec un
        // message qui dit la verite (IA indisponible, poursuite manuelle).
        $response->assertRedirect(route('organization.requests.create', [
            'organization' => $this->organization->slug,
        ]));
        $response->assertSessionMissing('help_request_error');
        $response->assertSessionHas('info', __('loops.help_request_ai_unavailable'));
    }

    public function test_gate_one_off_the_member_own_words_reach_the_canonical_form(): void
    {
        $intention = 'Je cherche des retours pour structurer une demande de mentorat';

        $post = $this->analyze($intention);

        // Le brouillon transporte les mots DU MEMBRE — jamais un contenu
        // invente : pas de titre fabrique, pas de categorie sortie de nulle
        // part, la Boucle d'origine comme destination reelle.
        $draft = app(HelpRequestHandoff::class)->pullDraft($this->member, $this->organization);
        $this->assertSame('', $draft['title']);
        $this->assertSame($intention, $draft['description']);
        $this->assertSame($this->loop->id, $draft['relay_loop_id']);
        $this->assertNull($draft['category_id']);
    }

    public function test_gate_one_off_the_canonical_form_prefills_the_member_words(): void
    {
        $intention = 'Je cherche des retours pour structurer une demande de mentorat';

        $post = $this->analyze($intention);

        $html = $this->actingAs($this->member)
            ->get($post->headers->get('Location'))
            ->assertOk()
            ->getContent();

        // Poursuite manuelle EFFECTIVE : le texte du membre est deja dans le
        // formulaire canonique, sans ressaisie.
        $this->assertStringContainsString($intention, $html);
    }

    public function test_gate_one_off_no_provider_no_trace_no_publication(): void
    {
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('Gate OFF : aucun appel provider ne doit etre tente.');
        });

        $this->analyze()->assertRedirect();

        // Aucun appel, aucune trace, aucune publication automatique.
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_gate_one_off_the_chatloop_screen_offers_the_manual_path(): void
    {
        $html = $this->actingAs($this->member)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->getContent();

        // L'entree du parcours reste visible (declencheur du modal)...
        $this->assertStringContainsString(e(__('loops.who_can_help')), $html);
        $this->assertStringContainsString('bp-open-help-request', $html);
        // ... et le modal degrade proprement : message non trompeur + CTA vers
        // le formulaire canonique.
        $this->assertStringContainsString('data-ai-unavailable', $html);
        $this->assertStringContainsString(e(__('loops.help_request_ai_unavailable')), $html);
        $this->assertStringContainsString('data-prepare-manually', $html);
        $this->assertStringContainsString(e(__('loops.help_request_prepare_manually')), $html);
        // La degradation n'affiche jamais le formulaire d'intention IA.
        $this->assertStringNotContainsString('help-request/analyze', $html);
    }

    // =====================================================================
    // B. Gate 2 OFF (gate 1 ON) — le repli deterministe ne se fait jamais
    //    passer pour une IA reelle
    // =====================================================================

    public function test_gate_two_off_the_result_is_marked_as_prepared_without_ai(): void
    {
        AiConfig::set('clarification_enabled', true);

        $post = $this->analyze();
        $post->assertRedirect();

        $html = $this->actingAs($this->member)
            ->get($post->headers->get('Location'))
            ->assertOk()
            ->getContent();

        // Le contenu du repli est la, editable, le parcours continue — mais
        // marque comme prepare SANS IA, jamais presente comme une reponse IA.
        $this->assertStringContainsString('data-prepared-without-ai', $html);
        $this->assertStringContainsString(e(__('loops.help_request_prepared_without_ai')), $html);
        $this->assertStringContainsString(e(__('loops.help_request_continue_cta')), $html);
    }

    // =====================================================================
    // C. Indisponibilites en aval des gates — meme degradation propre
    // =====================================================================

    public function test_provider_failure_degrades_and_is_marked_without_ai(): void
    {
        $this->enableAiPath();
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('provider down');
        });

        $post = $this->analyze();
        $post->assertRedirect();
        $post->assertSessionMissing('help_request_error');

        $html = $this->actingAs($this->member)
            ->get($post->headers->get('Location'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-prepared-without-ai', $html);
        $this->assertStringContainsString(e(__('loops.help_request_continue_cta')), $html);
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_economic_refusal_degrades_without_any_provider_call(): void
    {
        $this->enableAiPath();
        // Budget mensuel de l'Organization a zero : refus AVANT tout appel.
        OrganizationAiSetting::query()
            ->where('organization_id', $this->organization->id)
            ->update(['monthly_budget_usd' => 0]);
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('Refus economique : aucun appel provider ne doit etre tente.');
        });

        $post = $this->analyze();
        $post->assertRedirect();

        // Refus pre-provider : aucune trace, aucune consommation fictive.
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertDatabaseCount('ai_provider_invocations', 0);

        $html = $this->actingAs($this->member)
            ->get($post->headers->get('Location'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-prepared-without-ai', $html);
        $this->assertStringContainsString(e(__('loops.help_request_continue_cta')), $html);
    }

    /**
     * Le trou 500 ferme par cette TASK : gates ON, provider configure, budget
     * OK — mais aucun AdminAiPrompt actif. `clarifyInstructions()` jette une
     * DomainException qui n'etait attrapee nulle part sur l'Entree A.
     */
    public function test_missing_active_prompt_degrades_instead_of_a_500(): void
    {
        $this->enableAiPath();
        AdminAiPrompt::query()->delete();
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('Sans prompt actif, aucun appel provider ne doit etre tente.');
        });

        $post = $this->analyze();

        // Jamais un 500 pour le membre : degradation deterministe, parcours
        // praticable.
        $post->assertRedirect();
        $this->assertTrue(app(HelpRequestHandoff::class)->has($this->member, $this->loop));

        $html = $this->actingAs($this->member)
            ->get(route('loops.show', $this->loop))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-prepared-without-ai', $html);
        $this->assertStringContainsString(e(__('loops.help_request_continue_cta')), $html);
    }

    // =====================================================================
    // D. La degradation n'ouvre RIEN : tenant et appartenance inchanges
    // =====================================================================

    public function test_a_non_member_is_still_refused_when_the_gate_is_off(): void
    {
        $this->actingAs($this->nonMember)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'Je cherche des retours pour structurer une demande',
            ])
            ->assertNotFound();

        $this->assertNull(app(HelpRequestHandoff::class)->pullDraft($this->nonMember, $this->organization));
    }

    public function test_a_cross_organization_actor_is_still_refused_when_the_gate_is_off(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->actingAs($etranger)
            ->post(route('loops.help-request.analyze', $this->loop), [
                'intention' => 'Je cherche des retours pour structurer une demande',
            ])
            ->assertNotFound();

        $this->assertNull(app(HelpRequestHandoff::class)->pullDraft($etranger, $this->otherOrganization));
        $this->assertNull(app(HelpRequestHandoff::class)->pullDraft($etranger, $this->organization));
    }

    public function test_a_guest_is_still_redirected_to_login_when_the_gate_is_off(): void
    {
        $this->post(route('loops.help-request.analyze', $this->loop), [
            'intention' => 'Je cherche des retours pour structurer une demande',
        ])->assertRedirect(route('login'));
    }

    // =====================================================================
    // E. Aucune publication automatique, quel que soit le chemin degrade
    // =====================================================================

    public function test_no_degraded_path_ever_creates_a_service_request(): void
    {
        // Gate 1 OFF.
        $this->analyze()->assertRedirect();

        // Gate 1 ON, gate 2 OFF (repli deterministe).
        AiConfig::set('clarification_enabled', true);
        $this->analyze()->assertRedirect();

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
    }
}
