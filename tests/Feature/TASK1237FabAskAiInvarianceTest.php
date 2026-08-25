<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiFabContext;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1237 — le FAB expose desormais « Demander a l'IA » (loop_ask), migree
 * canonique par TASK-1233. Contrat unique : le FAB est un ROUTEUR — il ouvre
 * exactement le formulaire historique de loop-chat.blade.php (meme route
 * `loops.ai`, meme controleur `LoopController::askAi`, meme
 * `ChatLoopAiService::ask`), jamais une variante. Ces tests prouvent
 * l'invariance : meme garde de permission (canContribute), meme controle
 * economique (AiEconomicGuard), meme refus, avant et apres l'ajout.
 */
class TASK1237FabAskAiInvarianceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1237', 'name' => 'Org FAB Ask']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'monthly_budget_usd' => null,
        ]);

        $this->owner = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'first_name' => '1237']);
        $this->member = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'name' => 'Member', 'first_name' => '1237']);

        app()->instance('current_organization', $this->organization);
        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->owner, 'Boucle FAB Ask');
        $loops->addMember($this->loop, $this->member, 'member');

        config(['ai.chatloop.enabled' => true, 'ai.fab.enabled' => true]);
        Http::preventStrayRequests();
    }

    // =====================================================================
    // Chemin unique : le FAB ouvre le formulaire historique, jamais un second
    // =====================================================================

    public function test_the_fab_ask_ai_action_points_to_the_same_endpoint_as_the_historical_button(): void
    {
        $aiRoute = route('organization.loops.ai', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]);

        $response = $this->actingAs($this->member)->get($this->loopUrl());
        $response->assertOk();
        $html = $response->getContent();

        // Le contexte serveur du FAB expose loop_ask, route vers l'evenement.
        $context = $this->contextFor($this->member);
        $askAction = collect($context['actions'])->firstWhere('key', AiFabContext::ACTION_LOOP_ASK);
        $this->assertNotNull($askAction, 'loop_ask doit etre propose a un membre actif');
        $this->assertSame('event', $askAction['kind']);
        $this->assertSame('bp-open-ask-ai', $askAction['event']);

        // Un seul formulaire poste vers la route canonique : le FAB n'en cree
        // pas un second. Un seul champ `question`, une seule ecoute de
        // l'evenement que le FAB dispatche.
        $this->assertSame(1, substr_count($html, 'action="'.$aiRoute.'"'), 'un seul formulaire vers la route canonique');
        $this->assertSame(1, substr_count($html, 'name="question"'), 'un seul champ question');
        $this->assertSame(1, substr_count($html, '@bp-open-ask-ai.window'), 'le FAB ouvre le formulaire existant, pas un nouveau');
        $this->assertStringContainsString('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_ASK.'"', $html);
    }

    // =====================================================================
    // Permission : meme garde que canContribute() (LoopChat) des deux cotes
    // =====================================================================

    public function test_the_ask_ai_action_and_the_historical_button_disappear_together_for_a_non_contributor(): void
    {
        // Boucle archivee : canContribute() devient faux pour tout le monde.
        $this->loop->update(['status' => 'archived']);

        $response = $this->actingAs($this->member)->get($this->loopUrl());
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_ASK.'"', $html);
        $this->assertStringNotContainsString(__('loops.ask_ai_button'), $html);

        $context = $this->contextFor($this->member);
        $this->assertSame([], $context['actions']);
    }

    // =====================================================================
    // Economie : le refus au plafond est celui d'AiEconomicGuard, sans repli
    // =====================================================================

    public function test_posting_to_the_endpoint_the_fab_opens_is_refused_at_the_cap_with_the_same_code_as_the_historical_surface(): void
    {
        $this->platformQuota(1);
        $this->uses($this->member, 1);
        $before = $this->counters($this->member);

        // Ce que la soumission du formulaire — ouvert par le FAB comme par le
        // bouton historique — envoie au serveur.
        $response = $this->actingAs($this->member)->post(
            route('organization.loops.ai', ['organization' => $this->organization, 'loop' => $this->loop]),
            ['action' => 'ask', 'question' => 'Une de trop ?']
        );

        $response->assertRedirect(route('organization.loops.show', ['organization' => $this->organization, 'loop' => $this->loop]))
            ->assertSessionHas('ai_refusal_code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
            ->assertSessionHas('ai_offers_url');

        $this->assertSame($before, $this->counters($this->member), 'zero ledger, zero interaction : le refus precede tout appel');

        // Le FAB, lui, remplace toutes les actions (dont loop_ask) par le
        // refus au plafond — meme regle deja en vigueur depuis TASK-1231 pour
        // loop_knowledge/loop_summary/help_request, desormais verifiee pour
        // loop_ask aussi. Le refus est affiche avant toute soumission possible.
        $page = $this->actingAs($this->member)->get($this->loopUrl());
        $page->assertOk()
            ->assertDontSee('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_ASK.'"', false)
            ->assertSee('data-ai-fab-refusal', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function loopUrl(): string
    {
        return route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(User $user): array
    {
        app()->forgetScopedInstances();

        $response = $this->actingAs($user)->get($this->loopUrl());
        $response->assertOk();

        $context = app(AiFabContext::class)->forRequest(app('request'), $user);
        $this->assertNotNull($context);

        return $context;
    }

    private function platformQuota(?int $monthlyUses): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => 80,
            'offer_subscription' => true,
        ], $this->owner);
    }

    private function uses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiInteraction::create([
                'user_id' => $user->id,
                'organization_id' => $this->organization->id,
                'correlation_id' => (string) Str::uuid(),
                'process' => 'chatloop.ask',
                'feature' => 'chatloop_ai_ask',
                'model' => 'openai/gpt-4o-mini',
                'prompt' => 'p',
                'response' => 'r',
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cost_usd' => 0.001,
                'cost_unknown' => false,
                'metadata' => ['provider' => 'openai', 'status' => 'success'],
            ]);
        }
    }

    /**
     * @return array{interactions: int, ledger: int, credit_used: int}
     */
    private function counters(User $user): array
    {
        return [
            'interactions' => AiInteraction::query()->where('user_id', $user->id)->count(),
            'ledger' => AiProviderInvocation::query()->where('user_id', $user->id)->count(),
            'credit_used' => app(AiEconomicGuard::class)->userCreditStatus($this->organization, $user)->used,
        ];
    }
}
