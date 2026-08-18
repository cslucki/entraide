<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiFabContext;
use App\Support\Loops\LoopCardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1231 — FAB « BouclePro IA » : point d'entree unique et contextuel.
 *
 * Contrats :
 *  - la liste des actions est calculee cote serveur, avec les MEMES gardes
 *    que les boutons de la page (membre actif, Boucle ouverte, ChatLoop actif,
 *    Card resume placee, clarification activee, pilote recherche Dossier) ;
 *  - jamais de capability sans page qui la porte ; jamais « Demander a l'IA » ;
 *  - credit : AiEconomicGuard::userCreditStatus, une lecture par requete ;
 *    ambre a l'alerte ; au plafond, actions REMPLACEES par le refus + « Voir
 *    les offres » sans aucun appel (zero ligne de ledger, zero trace) ;
 *  - jamais dans guest / admin / org-admin ; kill-switch config.
 */
class TASK1231AiFabTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private User $stranger;

    private User $superAdmin;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-fab', 'name' => 'Org Fab']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'monthly_budget_usd' => null,
        ]);

        $this->owner = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'first_name' => 'Fab']);
        $this->member = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'name' => 'Member', 'first_name' => 'Fab']);
        $this->stranger = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'name' => 'Stranger', 'first_name' => 'Fab']);
        $this->superAdmin = User::factory()->complete()->create(['organization_id' => $this->organization->id, 'name' => 'Super', 'first_name' => 'Fab', 'is_admin' => true]);

        app()->instance('current_organization', $this->organization);
        $loops = new LoopService;
        $this->loop = $loops->createLoop($this->owner, 'Boucle FAB');
        $loops->addMember($this->loop, $this->member, 'member');

        config(['ai.chatloop.enabled' => true, 'ai.fab.enabled' => true]);
        Http::preventStrayRequests();
    }

    // =====================================================================
    // Page Boucle : les actions suivent les gardes de la page
    // =====================================================================

    public function test_on_a_loop_page_an_active_member_gets_the_loop_actions_and_never_ask_ai(): void
    {
        AiConfig::set('clarification_enabled', true);

        $page = $this->actingAs($this->member)->get($this->loopUrl());

        $page->assertOk()
            ->assertSee('data-ai-fab-page="loop"', false)
            ->assertSee('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_KNOWLEDGE.'"', false)
            ->assertSee('data-ai-fab-action="'.AiFabContext::ACTION_HELP_REQUEST.'"', false)
            ->assertSee(__('ai.fab_action_loop_knowledge'))
            ->assertSee(__('ai.fab_action_help_request'));

        // Le resume n'est propose que si la Card est placee pour cet
        // utilisateur dans cette Boucle — meme source que la barre d'actions.
        $summaryPlaced = app(LoopCardRegistry::class)->chatActionCardsFor($this->loop, $this->member)->contains('key', 'core.ai_summary');
        if ($summaryPlaced) {
            $page->assertSee('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_SUMMARY.'"', false);
        } else {
            $page->assertDontSee('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_SUMMARY.'"', false);
        }

        // Le contexte serveur ne connait que les quatre actions canoniques :
        // « Demander a l'IA » (chemin herite) n'y figure dans aucun cas.
        $context = $this->contextFor($this->member, $this->loopUrl());
        $this->assertSame('loop', $context['page']);
        foreach ($context['actions'] as $action) {
            $this->assertContains($action['key'], [
                AiFabContext::ACTION_LOOP_KNOWLEDGE,
                AiFabContext::ACTION_LOOP_SUMMARY,
                AiFabContext::ACTION_HELP_REQUEST,
                AiFabContext::ACTION_DOSSIER_SEARCH,
            ]);
            $this->assertSame('event', $action['kind']);
        }
        $this->assertStringNotContainsString('ask-ai', json_encode($context));
    }

    public function test_help_request_is_not_proposed_when_clarification_is_disabled(): void
    {
        AiConfig::set('clarification_enabled', false);

        $context = $this->contextFor($this->member, $this->loopUrl());

        $this->assertNotContains(AiFabContext::ACTION_HELP_REQUEST, array_column($context['actions'], 'key'));
        $this->assertContains(AiFabContext::ACTION_LOOP_KNOWLEDGE, array_column($context['actions'], 'key'));
    }

    public function test_a_non_member_and_an_archived_loop_get_no_loop_action(): void
    {
        $this->loop->update(['is_public' => true]);
        $context = $this->contextFor($this->stranger, $this->loopUrl());
        $this->assertSame('loop', $context['page']);
        $this->assertSame([], $context['actions']);

        $this->loop->update(['status' => 'archived']);
        $context = $this->contextFor($this->member, $this->loopUrl());
        $this->assertSame([], $context['actions']);
    }

    public function test_when_chatloop_is_disabled_no_loop_action_is_proposed(): void
    {
        config(['ai.chatloop.enabled' => false]);

        $context = $this->contextFor($this->member, $this->loopUrl());

        $this->assertSame([], $context['actions']);
    }

    // =====================================================================
    // Page Dossier
    // =====================================================================

    public function test_on_a_dossier_page_the_search_is_proposed_only_when_the_pilot_is_open_to_the_organization(): void
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier FAB',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
        $url = route('organization.dossiers.show', ['organization' => $this->organization->slug, 'dossier' => $dossier->id]);

        config(['ai.dossiers.semantic_search.enabled' => true, 'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id]]);
        $context = $this->contextFor($this->member, $url);
        $this->assertSame('dossier', $context['page']);
        $this->assertSame([AiFabContext::ACTION_DOSSIER_SEARCH], array_column($context['actions'], 'key'));
        $this->assertSame('bp-open-dossier-search', $context['actions'][0]['event']);

        config(['ai.dossiers.semantic_search.enabled' => false]);
        $context = $this->contextFor($this->member, $url);
        $this->assertSame([], $context['actions']);
    }

    // =====================================================================
    // Ailleurs : usages + credit, rien d'autre
    // =====================================================================

    public function test_elsewhere_the_fab_only_offers_usage_and_credit(): void
    {
        $page = $this->actingAs($this->member)->get(route('organization.profile.ai-usage', ['organization' => $this->organization->slug]));

        $page->assertOk()
            ->assertSee('data-ai-fab-page="other"', false)
            ->assertDontSee('data-ai-fab-action=', false)
            ->assertSee('data-ai-fab-usage', false)
            ->assertSee(__('ai.fab_credit_included'))
            ->assertSee(route('organization.profile.ai-usage', ['organization' => $this->organization->slug]));
    }

    // =====================================================================
    // Credit : autorite unique, tons, plafond sans appel
    // =====================================================================

    public function test_the_credit_shown_is_the_one_that_blocks_and_turns_amber_at_the_alert(): void
    {
        $this->platformQuota(5);
        $this->uses($this->member, 4);

        $page = $this->actingAs($this->member)->get($this->loopUrl());

        $page->assertOk()
            ->assertSee('data-ai-fab-tone="alert"', false)
            ->assertSee('data-ai-fab-alert', false)
            ->assertSee(trans_choice('ai.credit_remaining', 1))
            ->assertSee('data-ai-fab-action="'.AiFabContext::ACTION_LOOP_KNOWLEDGE.'"', false);

        $expected = app(AiEconomicGuard::class)->userCreditStatus($this->organization, $this->member);
        $context = $this->contextFor($this->member, $this->loopUrl());
        $this->assertSame($expected->toArray(), $context['credit']);
    }

    public function test_at_the_cap_actions_are_replaced_by_the_refusal_and_offers_without_any_call(): void
    {
        $this->platformQuota(2);
        $this->uses($this->member, 2);
        $interactions = AiInteraction::query()->count();
        $ledger = AiProviderInvocation::query()->count();

        $page = $this->actingAs($this->member)->get($this->loopUrl());

        $page->assertOk()
            ->assertSee('data-ai-fab-tone="exhausted"', false)
            ->assertSee('data-ai-fab-refusal', false)
            ->assertSee('data-ai-fab-offers', false)
            ->assertSee(__('ai.credit_see_offers'))
            ->assertSee(e(trans_choice('ai.credit_refusal_user_exhausted', 2, ['used' => 2, 'quota' => 2, 'date' => now()->startOfMonth()->addMonth()->format('d/m/Y')])), false)
            ->assertDontSee('data-ai-fab-action=', false);

        // Rien n'a ete appele ni ecrit : le FAB ne fait qu'afficher le verdict.
        $this->assertSame($interactions, AiInteraction::query()->count());
        $this->assertSame($ledger, AiProviderInvocation::query()->count());
    }

    public function test_offers_are_hidden_at_the_cap_when_the_platform_does_not_offer_a_subscription(): void
    {
        $this->platformQuota(1, offerSubscription: false);
        $this->uses($this->member, 1);

        $page = $this->actingAs($this->member)->get($this->loopUrl());

        $page->assertOk()
            ->assertSee('data-ai-fab-refusal', false)
            ->assertDontSee('data-ai-fab-offers', false);
    }

    public function test_the_credit_is_read_once_per_request(): void
    {
        $request = Request::create($this->loopUrl(), 'GET');
        $context = app(AiFabContext::class);

        $first = $context->forRequest($request, $this->member);

        DB::enableQueryLog();
        $second = $context->forRequest($request, $this->member);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertCount(0, $queries, 'Le contexte est memorise pour la requete : aucune relecture du credit.');
    }

    // =====================================================================
    // Placement : layout membre seulement ; kill-switch
    // =====================================================================

    public function test_the_fab_is_absent_from_guest_admin_and_org_admin_layouts(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-ai-fab', false);

        $this->actingAs($this->superAdmin)->get(route('admin.ia-usage'))->assertOk()->assertDontSee('data-ai-fab', false);

        $this->organization->update(['admin_id' => $this->owner->id]);
        $this->actingAs($this->owner)
            ->get(route('organization.admin.system-email-templates', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertDontSee('data-ai-fab', false);
    }

    public function test_the_kill_switch_removes_the_fab(): void
    {
        config(['ai.fab.enabled' => false]);

        $this->actingAs($this->member)->get($this->loopUrl())->assertOk()->assertDontSee('data-ai-fab', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function loopUrl(): string
    {
        return route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop->id]);
    }

    /**
     * Le contexte tel que le composant le recoit : un vrai GET (route resolue,
     * Organization courante, bindings), puis la meme lecture que le composant
     * — l'instance `scoped` de la requete rend le tableau deja calcule.
     *
     * @return array<string, mixed>
     */
    private function contextFor(User $user, string $url): array
    {
        // En test, l'instance `scoped` survit d'un GET a l'autre dans le meme
        // processus ; on la libere comme le ferait une nouvelle requete.
        app()->forgetScopedInstances();

        $response = $this->actingAs($user)->get($url);
        $response->assertOk();
        $this->assertStringContainsString('data-ai-fab', $response->getContent());

        $context = app(AiFabContext::class)->forRequest(app('request'), $user);
        $this->assertNotNull($context);

        return $context;
    }

    private function platformQuota(?int $monthlyUses, bool $offerSubscription = true): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => 80,
            'offer_subscription' => $offerSubscription,
        ], $this->superAdmin);
    }

    private function uses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiInteraction::create([
                'user_id' => $user->id,
                'organization_id' => $this->organization->id,
                'correlation_id' => (string) Str::uuid(),
                'process' => 'loop_knowledge.answer',
                'feature' => 'loop_knowledge_answer',
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
}
