<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\LoopSummaryAgent;
use App\Livewire\LoopAiSummaryCard;
use App\Models\AiConfig;
use App\Models\AiCreditSettingChange;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Ai\OrganizationDoctrineSandbox;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiEconomicVerdict;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUserCreditPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1229 — credit IA par utilisateur et seuils d'abonnement V1.
 *
 * Trois notions, trois etats, trois messages — jamais confondus :
 *   cout fournisseur / budget Organization (monnaie) / credit utilisateur
 *   (UTILISATIONS). Le credit s'applique DANS `AiEconomicGuard` (jamais une
 *   seconde garde), AVANT tout appel provider : zero invocation, zero ligne de
 *   ledger, aucune utilisation decomptee sur un refus.
 *
 *  A. CASCADE — plateforme, override Organization, illimite, IA gratuite
 *     desactivee, mode custom sans valeur.
 *  B. BLOCAGE — au plafond : refus avant appel (endpoint knowledge, resume,
 *     recherche directe), code stable, autres membres non bloques.
 *  C. ALERTE — seuil franchi : message present, action non bloquee.
 *  D. TROIS ETATS — credit / budget Organization / credential absent :
 *     trois codes, trois messages ; budget atteint + credit intact => le
 *     message parle de l'Organization.
 *  E. COMPTAGE — l'inconnu compte, le bac a sable ne compte pas (mais compte
 *     dans le budget), meme fenetre que le budget, echecs et indexations.
 *  F. TENANT / PERMISSIONS — son credit seul ; 403 x3 ; A ne s'applique
 *     jamais a B ; trace des changements (auteur).
 */
class TASK1229UserAiCreditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $memberA;

    private User $memberA2;

    private User $adminB;

    private User $memberB;

    private User $superAdmin;

    private Loop $loop;

    private Dossier $dossier;

    private Task1229FakeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org Alpha 1229']);
        $this->orgB = Organization::factory()->create(['name' => 'Org Beta 1229']);

        foreach ([$this->orgA, $this->orgB] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openrouter',
                'model' => 'openai/gpt-4o-mini',
                'api_key' => 'sk-or-task1229',
                'monthly_budget_usd' => null,
            ]);
        }

        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Admin Alpha']);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Un']);
        $this->memberA2 = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Membre Alpha Deux']);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Admin Beta']);
        $this->orgB->update(['admin_id' => $this->adminB->id]);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Membre Beta']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Super Admin', 'is_admin' => true]);

        $loops = new LoopService;
        app()->instance('current_organization', $this->orgA);
        $this->loop = $loops->createLoop($this->memberA, 'Boucle 1229');
        $loops->addMember($this->loop, $this->memberA2, 'member');

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->memberA2->id,
            'name' => 'Dossier partage 1229',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->orgA->id, $this->orgB->id],
            'ai.chatloop.min_summary_words' => 0,
            'ai_pricing.overrides' => [],
        ]);
        AiConfig::set('default_provider', 'openrouter');
        AiConfig::set('default_model', 'openai/gpt-4o-mini');

        $this->search = new Task1229FakeSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);
        $this->search->rows = [$this->row('A')];

        Http::preventStrayRequests();
    }

    // =====================================================================
    // A. CASCADE
    // =====================================================================

    public function test_without_override_the_platform_value_applies(): void
    {
        $this->platform(monthlyUses: 3);

        $policy = $this->settings()->policyFor($this->orgA);

        $this->assertSame(3, $policy->monthlyUses);
        $this->assertSame(AiUserCreditPolicy::SOURCE_PLATFORM, $policy->source);
        $this->assertSame(80, $policy->alertPercent);
        $this->assertFalse($policy->isUnlimited());
    }

    public function test_an_organization_override_primes_over_the_platform_and_never_leaks_to_another(): void
    {
        $this->platform(monthlyUses: 3);
        $this->settings()->updateOrganization($this->orgA, OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 5, $this->adminA);

        $this->assertSame(5, $this->settings()->policyFor($this->orgA)->monthlyUses);
        $this->assertSame(AiUserCreditPolicy::SOURCE_ORGANIZATION, $this->settings()->policyFor($this->orgA)->source);
        // Le quota de A ne s'applique jamais a B.
        $this->assertSame(3, $this->settings()->policyFor($this->orgB)->monthlyUses);
        $this->assertSame(AiUserCreditPolicy::SOURCE_PLATFORM, $this->settings()->policyFor($this->orgB)->source);
    }

    public function test_unlimited_at_either_level_never_blocks(): void
    {
        // Plateforme illimitee (quota vide) : 50 utilisations, aucun blocage.
        $this->platform(monthlyUses: null);
        $this->uses($this->memberA, 50);
        $this->assertTrue($this->guardVerdict($this->memberA)->allowed);
        $this->assertTrue($this->guard()->userCreditStatus($this->orgA, $this->memberA)->isUnlimited());

        // Plateforme a 1, Organization « illimite » : elle prime, aucun blocage.
        $this->platform(monthlyUses: 1);
        $this->settings()->updateOrganization($this->orgA, OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED, null, $this->adminA);
        $verdict = $this->guardVerdict($this->memberA);
        $this->assertTrue($verdict->allowed);
        $this->assertTrue($verdict->userCredit->isUnlimited());
        $this->assertSame(50, $verdict->userCredit->used);
    }

    public function test_free_ai_disabled_means_no_use_included_unless_the_organization_overrides(): void
    {
        $this->platform(monthlyUses: 100, freeEnabled: false);

        // Aucune utilisation encore : refus quand meme, quota effectif 0.
        $verdict = $this->guardVerdict($this->memberA);
        $this->assertFalse($verdict->allowed);
        $this->assertSame(AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED, $verdict->reason);
        $this->assertSame(0, $verdict->userCredit->quota());
        // Le message a quota nul dit « aucune utilisation incluse », jamais « epuise (0 sur 0) ».
        $message = AiRefusedException::fromVerdict($verdict)->getMessage();
        $this->assertStringContainsString(__('ai.credit_none_included'), $message);
        $this->assertStringNotContainsString('0 sur 0', $message);
        $this->assertStringNotContainsString('0 of 0', $message);

        // L'override d'Organization prime : deux utilisations incluses.
        $this->settings()->updateOrganization($this->orgA, OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 2, $this->adminA);
        $this->assertTrue($this->guardVerdict($this->memberA)->allowed);
        // …et B, sans override, reste a zero.
        $this->assertFalse($this->guardVerdict($this->memberB, $this->orgB)->allowed);
    }

    public function test_a_custom_mode_without_a_value_falls_back_to_the_platform_never_to_unlimited(): void
    {
        $this->platform(monthlyUses: 4);
        OrganizationAiSetting::query()->where('organization_id', $this->orgA->id)
            ->update(['user_credit_mode' => OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 'user_credit_monthly_uses' => null]);

        $policy = $this->settings()->policyFor($this->orgA);

        $this->assertSame(4, $policy->monthlyUses);
        $this->assertSame(AiUserCreditPolicy::SOURCE_PLATFORM, $policy->source);
    }

    // =====================================================================
    // B. BLOCAGE
    // =====================================================================

    public function test_at_the_cap_the_knowledge_answer_is_refused_before_any_call_with_zero_ledger_and_nothing_deducted(): void
    {
        $this->platform(monthlyUses: 2);
        $this->uses($this->memberA, 2);
        $this->fakeKnowledgeAgent('ne doit pas etre appele');

        $interactionsBefore = AiInteraction::query()->count();
        $ledgerBefore = AiProviderInvocation::query()->count();

        $response = $this->actingAs($this->memberA)->postJson(
            route('organization.loops.knowledge.ask', ['organization' => $this->orgA->slug, 'loop' => $this->loop->id]),
            ['question' => 'Que doit contenir une installation itinérante ?'],
        );

        $response->assertStatus(422)
            ->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
            ->assertJsonPath('error', trans_choice('ai.credit_refusal_user_exhausted', 2, ['used' => 2, 'quota' => 2, 'date' => CarbonImmutable::now()->startOfMonth()->addMonth()->format('d/m/Y')]));
        $this->assertNotNull($response->json('offers_url'));

        // Zero appel provider, zero trace, zero ligne de ledger, rien de decompte.
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertNull($this->search->lastCall);
        $this->assertSame($interactionsBefore, AiInteraction::query()->count());
        $this->assertSame($ledgerBefore, AiProviderInvocation::query()->count());
        $this->assertSame(2, $this->guard()->userCreditStatus($this->orgA, $this->memberA)->used);
    }

    public function test_a_blocked_member_does_not_prevent_the_others_from_working(): void
    {
        $this->platform(monthlyUses: 2);
        $this->uses($this->memberA, 2);
        $this->fakeKnowledgeAgent('Réponse sourcee [S1].');

        $response = $this->actingAs($this->memberA2)->postJson(
            route('organization.loops.knowledge.ask', ['organization' => $this->orgA->slug, 'loop' => $this->loop->id]),
            ['question' => 'Que doit contenir une installation itinérante ?'],
        );

        $response->assertOk();
        $this->assertStringContainsString('[S1]', $response->json('answer'));
        // Le credit APRES la reponse : 1 utilisation sur 2 (aucune alerte a 50 %).
        $this->assertSame(1, $response->json('credit.used'));
        $this->assertSame(2, $response->json('credit.quota'));
        $this->assertFalse($response->json('credit.alert'));
        $this->assertFalse($response->json('credit.exhausted'));
        $this->assertSame(1, AiInteraction::query()->where('user_id', $this->memberA2->id)->count());
    }

    public function test_the_loop_summary_card_refuses_with_the_credit_code_and_no_call(): void
    {
        $this->platform(monthlyUses: 1);
        $this->uses($this->memberA, 1);
        LoopSummaryAgent::fake([new TextResponse('jamais', new Usage(1, 1), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        Livewire::actingAs($this->memberA)
            ->test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->call('generate')
            ->assertSet('errorCode', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
            ->assertSee(__('ai.credit_see_offers'));

        LoopSummaryAgent::assertNeverPrompted();
        $this->assertSame(1, AiInteraction::query()->where('user_id', $this->memberA->id)->count());
    }

    public function test_the_direct_document_search_refuses_with_the_credit_code_before_any_embedding(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Semantic search requires PostgreSQL pgvector (the driver check precedes the guard).');
        }

        $this->platform(monthlyUses: 1);
        $this->uses($this->memberA, 1);
        // Le vrai service, pour eprouver le refus AVANT l'embedding (aucun
        // pgvector requis : la garde tranche avant).
        $this->app->forgetInstance(DossierSemanticSearchService::class);
        $ledgerBefore = AiProviderInvocation::query()->count();

        $response = $this->actingAs($this->memberA)->getJson(
            route('organization.dossiers.semantic-search', ['organization' => $this->orgA->slug, 'dossier' => $this->dossier->id, 'query' => 'installation itinérante']),
        );

        $response->assertStatus(429)->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);
        $this->assertNotNull($response->json('offers_url'));
        $this->assertSame($ledgerBefore, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // C. ALERTE
    // =====================================================================

    public function test_crossing_the_alert_threshold_shows_a_message_without_blocking(): void
    {
        $this->platform(monthlyUses: 5, alertPercent: 80);
        $this->uses($this->memberA, 3);
        $this->fakeKnowledgeAgent('Réponse sourcee [S1].');

        $response = $this->actingAs($this->memberA)->postJson(
            route('organization.loops.knowledge.ask', ['organization' => $this->orgA->slug, 'loop' => $this->loop->id]),
            ['question' => 'Que doit contenir une installation itinérante ?'],
        );

        // Action NON bloquee, message d'alerte present : 4/5 = 80 %.
        $response->assertOk()
            ->assertJsonPath('credit.used', 4)
            ->assertJsonPath('credit.remaining', 1)
            ->assertJsonPath('credit.alert', true)
            ->assertJsonPath('credit.exhausted', false);

        $page = $this->actingAs($this->memberA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug]));
        $page->assertOk()
            ->assertSee('data-my-ai-credit-state="alert"', false)
            ->assertSee(__('ai.credit_alert_title'))
            ->assertSee(__('ai.credit_alert_remaining', ['remaining' => 1, 'used' => 4, 'quota' => 5]))
            ->assertDontSee('data-my-ai-credit-exhausted', false);
    }

    public function test_under_the_threshold_the_page_shows_what_remains_and_at_the_cap_a_clear_refusal_with_offers(): void
    {
        $this->platform(monthlyUses: 10, alertPercent: 80);
        $this->uses($this->memberA, 2);

        $page = $this->actingAs($this->memberA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug]));
        $page->assertOk()
            ->assertSee('data-my-ai-credit-state="ok"', false)
            ->assertSee(__('ai.credit_used_of_quota', ['used' => 2, 'quota' => 10]))
            ->assertSee(trans_choice('ai.credit_remaining', 8, ['count' => 8]))
            ->assertDontSee(__('ai.credit_see_offers'));

        $this->uses($this->memberA, 8);

        $page = $this->actingAs($this->memberA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug]));
        $page->assertOk()
            ->assertSee('data-my-ai-credit-state="exhausted"', false)
            ->assertSee(__('ai.credit_exhausted_title'))
            ->assertSee(__('ai.credit_see_offers'))
            ->assertSee(aiOffersUrl($this->orgA), false);

        // La page « Voir les offres » : information, aucun paiement.
        $offers = $this->actingAs($this->memberA)->get(aiOffersUrl($this->orgA));
        $offers->assertOk()->assertSee(__('ai.offers_title'))->assertSee(__('ai.offers_no_payment'));

        // « Proposer un abonnement » desactive : plus de bouton, refus toujours clair.
        $this->platform(monthlyUses: 10, offerSubscription: false);
        $page = $this->actingAs($this->memberA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug]));
        $page->assertOk()->assertSee(__('ai.credit_exhausted_title'))->assertDontSee(__('ai.credit_see_offers'));
    }

    // =====================================================================
    // D. TROIS ETATS
    // =====================================================================

    public function test_the_three_refusal_states_have_three_distinct_codes_and_messages(): void
    {
        $this->fakeKnowledgeAgent('jamais');
        $endpoint = route('organization.loops.knowledge.ask', ['organization' => $this->orgA->slug, 'loop' => $this->loop->id]);
        $payload = ['question' => 'Que doit contenir une installation itinérante ?'];

        // 1. Credit utilisateur epuise (budget Organization intact).
        $this->platform(monthlyUses: 1);
        $this->uses($this->memberA, 1);
        $credit = $this->actingAs($this->memberA)->postJson($endpoint, $payload);
        $credit->assertStatus(422)->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);

        // 2. Budget Organization atteint alors que le credit de A2 est intact :
        //    le message parle de l'Organization, pas du credit.
        OrganizationAiSetting::query()->where('organization_id', $this->orgA->id)->update(['monthly_budget_usd' => 0.001]);
        $this->generation($this->orgA, $this->memberA, cost: 0.50);
        $budget = $this->actingAs($this->memberA2)->postJson($endpoint, $payload);
        $budget->assertStatus(422)
            ->assertJsonPath('code', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED)
            ->assertJsonPath('error', __('loops.ai_summary_monthly_budget_reached'));
        $this->assertNull($budget->json('offers_url'));
        $this->assertStringNotContainsString(__('ai.credit_refusal_user_exhausted_short'), $budget->json('error'));

        // 3. Credential absent.
        OrganizationAiSetting::query()->where('organization_id', $this->orgA->id)->delete();
        $notConfigured = $this->actingAs($this->memberA2)->postJson($endpoint, $payload);
        $notConfigured->assertStatus(422)
            ->assertJsonPath('code', AiRefusedException::CODE_NOT_CONFIGURED)
            ->assertJsonPath('error', __('loops.ai_not_configured_for_organization'));

        $codes = [$credit->json('code'), $budget->json('code'), $notConfigured->json('code')];
        $messages = [$credit->json('error'), $budget->json('error'), $notConfigured->json('error')];
        $this->assertCount(3, array_unique($codes));
        $this->assertCount(3, array_unique($messages));
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_the_organization_budget_is_checked_before_the_user_credit_when_both_are_reached(): void
    {
        $this->platform(monthlyUses: 1);
        $this->uses($this->memberA, 1);
        OrganizationAiSetting::query()->where('organization_id', $this->orgA->id)->update(['monthly_budget_usd' => 0.001]);
        $this->generation($this->orgA, $this->memberA2, cost: 0.50);

        $verdict = $this->guardVerdict($this->memberA);

        // Quand l'Organization ne peut plus travailler, s'abonner n'y changerait
        // rien : le message parle de l'Organization.
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
        $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, AiRefusedException::fromVerdict($verdict)->refusalCode);
    }

    // =====================================================================
    // E. COMPTAGE
    // =====================================================================

    public function test_a_call_with_unmeasurable_cost_still_counts_as_one_use(): void
    {
        $this->platform(monthlyUses: 2);
        $this->generation($this->orgA, $this->memberA, cost: null);
        $this->generation($this->orgA, $this->memberA, cost: 0.01);

        $status = $this->guard()->userCreditStatus($this->orgA, $this->memberA);

        $this->assertSame(2, $status->used);
        $this->assertTrue($status->isExhausted());
        $this->assertFalse($this->guardVerdict($this->memberA)->allowed);
    }

    public function test_doctrine_sandbox_tests_do_not_count_toward_the_user_credit_but_do_count_toward_the_organization_budget(): void
    {
        $this->platform(monthlyUses: 2);
        // Deux essais de doctrine par l'admin, au cout reel.
        $this->generation($this->orgA, $this->adminA, cost: 0.30, feature: OrganizationDoctrineSandbox::FEATURE);
        $this->generation($this->orgA, $this->adminA, cost: 0.30, feature: OrganizationDoctrineSandbox::FEATURE);
        $this->generation($this->orgA, $this->adminA, cost: 0.01);

        // Credit : 1 seule utilisation (la generation productive), pas 3.
        $status = $this->guard()->userCreditStatus($this->orgA, $this->adminA);
        $this->assertSame(1, $status->used);
        $this->assertTrue($this->guardVerdict($this->adminA)->allowed);

        // L'ecran le dit : essais de doctrine hors credit.
        $page = $this->actingAs($this->adminA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug]));
        $page->assertOk()
            ->assertSee('data-my-ai-credit-used="1"', false)
            ->assertSee('data-my-ai-credit-sandbox-excluded="2"', false)
            ->assertSee(trans_choice('ai.credit_out_of_scope_sandbox_count', 2, ['count' => 2]));

        // Budget Organization : les 0.61 $ comptent — a 0.60 $ de budget, refus.
        OrganizationAiSetting::query()->where('organization_id', $this->orgA->id)->update(['monthly_budget_usd' => 0.60]);
        $verdict = $this->guardVerdict($this->memberA2);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
    }

    public function test_the_counter_resets_on_the_same_window_as_the_budget(): void
    {
        $this->platform(monthlyUses: 2);
        $now = CarbonImmutable::now();
        // Une utilisation juste AVANT la fenetre du budget, une juste dedans.
        $this->generation($this->orgA, $this->memberA, cost: 0.01)->forceFill(['created_at' => $now->startOfMonth()->subSecond()])->saveQuietly();
        $this->generation($this->orgA, $this->memberA, cost: 0.01)->forceFill(['created_at' => $now->startOfMonth()])->saveQuietly();

        $status = $this->guard()->userCreditStatus($this->orgA, $this->memberA);

        $this->assertSame(1, $status->used);
        $this->assertTrue($status->periodStart->equalTo($now->startOfMonth()));
        $this->assertTrue($status->renewsAt->equalTo($now->startOfMonth()->addMonth()));
        // La MEME fenetre que le budget (autorite 1228 / garde 1222).
        $this->assertTrue($status->periodStart->equalTo(AiConsumptionFilters::currentMonth()->from));
        $this->assertTrue($status->renewsAt->equalTo(AiConsumptionFilters::currentMonth()->to));
    }

    public function test_document_searches_count_but_indexings_and_undeclared_embeddings_do_not(): void
    {
        $this->platform(monthlyUses: 10);
        $this->embedding($this->orgA, $this->memberA, 'query', 0.0001);
        $this->embedding($this->orgA, $this->memberA, 'query', null);
        $this->embedding($this->orgA, $this->memberA, 'ingestion', 0.002);
        $this->embedding($this->orgA, $this->memberA, null, 0.002);
        $this->generation($this->orgA, $this->memberA, cost: 0.01);

        $status = $this->guard()->userCreditStatus($this->orgA, $this->memberA);

        // 2 recherches (dont 1 non mesurable) + 1 generation = 3.
        $this->assertSame(3, $status->used);
        $byUser = app(OrganizationAiEconomicUsage::class)->creditUsesByUser((string) $this->orgA->id, $status->periodStart, $status->renewsAt);
        $this->assertSame(3, $byUser[(string) $this->memberA->id]);
        $all = app(OrganizationAiEconomicUsage::class)->creditUsesByOrganizationAndUser($status->periodStart, $status->renewsAt);
        $this->assertSame(3, $all[(string) $this->orgA->id][(string) $this->memberA->id]);
    }

    // =====================================================================
    // F. TENANT / PERMISSIONS / TRACE
    // =====================================================================

    public function test_a_member_sees_only_their_own_credit_and_the_uses_of_others_never_count_against_them(): void
    {
        $this->platform(monthlyUses: 3);
        $this->uses($this->memberA2, 3);
        $this->uses($this->memberB, 3);

        $status = $this->guard()->userCreditStatus($this->orgA, $this->memberA);
        $this->assertSame(0, $status->used);
        $this->assertTrue($this->guardVerdict($this->memberA)->allowed);

        $page = $this->actingAs($this->memberA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug]));
        $page->assertOk()
            ->assertSee('data-my-ai-credit-used="0"', false)
            ->assertDontSee('Membre Alpha Deux')
            ->assertDontSee('Membre Beta');
    }

    public function test_the_quota_of_organization_a_never_applies_to_organization_b(): void
    {
        $this->platform(monthlyUses: 50);
        $this->settings()->updateOrganization($this->orgA, OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 1, $this->adminA);
        $this->uses($this->memberB, 5);

        $this->assertTrue($this->guardVerdict($this->memberB, $this->orgB)->allowed);
        $this->assertSame(50, $this->guard()->userCreditStatus($this->orgB, $this->memberB)->quota());
        // Et un membre de A avec la meme consommation est bloque.
        $this->uses($this->memberA, 5);
        $this->assertFalse($this->guardVerdict($this->memberA)->allowed);
    }

    public function test_permissions_on_the_configuration_surfaces(): void
    {
        $orgUrl = route('organization.admin.ai', ['organization' => $this->orgA->slug]);
        $orgUpdate = route('organization.admin.ai.user-credit.update', ['organization' => $this->orgA->slug]);
        $platformUrl = route('admin.ai-monetization');
        $payload = ['user_credit_mode' => OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM, 'user_credit_monthly_uses' => 7];

        // Membre non admin -> 403 sur la configuration de son Organization.
        $this->actingAs($this->memberA)->get($orgUrl)->assertForbidden();
        $this->actingAs($this->memberA)->put($orgUpdate, $payload)->assertForbidden();
        // Admin d'une autre Organization -> 403.
        $this->actingAs($this->adminB)->get($orgUrl)->assertForbidden();
        $this->actingAs($this->adminB)->put($orgUpdate, $payload)->assertForbidden();
        // Non-SuperAdmin -> 403 sur le parametre plateforme (admin d'Organization compris).
        $this->actingAs($this->memberA)->get($platformUrl)->assertForbidden();
        $this->actingAs($this->adminA)->get($platformUrl)->assertForbidden();
        $this->actingAs($this->adminA)->post(route('admin.ai-monetization.update'), ['alert_percent' => 50])->assertForbidden();
        // Rien n'a ete ecrit.
        $this->assertNull(OrganizationAiSetting::query()->where('organization_id', $this->orgA->id)->value('user_credit_mode'));
        $this->assertSame(0, AiCreditSettingChange::query()->count());

        // L'admin de A configure A ; le SuperAdmin configure la plateforme.
        $this->actingAs($this->adminA)->put($orgUpdate, $payload)->assertRedirect($orgUrl);
        $this->assertSame(7, $this->settings()->policyFor($this->orgA)->monthlyUses);
        $this->actingAs($this->superAdmin)->get($platformUrl)->assertOk()->assertSee(__('admin.ai_monetization_title'));
        $this->actingAs($this->superAdmin)->post(route('admin.ai-monetization.update'), [
            'free_enabled' => 1, 'monthly_uses' => 42, 'alert_percent' => 75, 'offer_subscription' => 1,
        ])->assertRedirect($platformUrl);
        $platform = $this->settings()->platform();
        $this->assertSame(42, $platform['monthly_uses']);
        $this->assertSame(75, $platform['alert_percent']);
        $this->assertTrue($platform['free_enabled']);
    }

    public function test_every_change_of_quota_is_traced_with_its_author(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.ai-monetization.update'), [
            'free_enabled' => 1, 'monthly_uses' => 20, 'alert_percent' => 80, 'offer_subscription' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->adminA)->put(route('organization.admin.ai.user-credit.update', ['organization' => $this->orgA->slug]), [
            'user_credit_mode' => OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED,
        ])->assertSessionHasNoErrors();

        $platformChange = AiCreditSettingChange::query()->where('scope', AiCreditSettingChange::SCOPE_PLATFORM)->firstOrFail();
        $this->assertSame($this->superAdmin->id, $platformChange->changed_by);
        $this->assertSame(20, $platformChange->changes['monthly_uses']['to']);
        $this->assertNotNull($platformChange->created_at);

        $orgChange = AiCreditSettingChange::query()->where('scope', AiCreditSettingChange::SCOPE_ORGANIZATION)->firstOrFail();
        $this->assertSame($this->adminA->id, $orgChange->changed_by);
        $this->assertSame($this->orgA->id, $orgChange->organization_id);
        $this->assertSame(OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED, $orgChange->changes['user_credit_mode']['to']);

        // Un enregistrement sans changement ne trace rien.
        $this->actingAs($this->adminA)->put(route('organization.admin.ai.user-credit.update', ['organization' => $this->orgA->slug]), [
            'user_credit_mode' => OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED,
        ]);
        $this->assertSame(2, AiCreditSettingChange::query()->count());

        // Les ecrans montrent l'auteur et la date.
        $this->actingAs($this->adminA)->get(route('organization.admin.ai', ['organization' => $this->orgA->slug]))
            ->assertOk()->assertSee('Admin Alpha')->assertSee('data-ai-user-credit-mode="unlimited"', false);
        $this->actingAs($this->superAdmin)->get(route('admin.ai-monetization'))
            ->assertOk()->assertSee('Super Admin')->assertSee('data-ai-monetization-organization="'.$this->orgA->slug.'"', false);
    }

    public function test_the_organization_admin_sees_who_approaches_their_limit_and_only_their_members(): void
    {
        $this->platform(monthlyUses: 5, alertPercent: 80);
        $this->uses($this->memberA, 4);
        $this->uses($this->memberA2, 5);
        $this->uses($this->memberB, 5);

        $page = $this->actingAs($this->adminA)->get(route('organization.admin.ai', ['organization' => $this->orgA->slug]));

        $page->assertOk()
            ->assertSee('Membre Alpha Un')
            ->assertSee('Membre Alpha Deux')
            ->assertSee(__('admin.organization_ai_user_credit_blocked_badge'))
            ->assertSee(__('admin.organization_ai_user_credit_alert_badge'))
            ->assertDontSee('Membre Beta');

        // Le releve Organization porte la colonne credit sur le mois courant.
        $consumption = $this->actingAs($this->adminA)->get(route('organization.admin.ai-consumption', ['organization' => $this->orgA->slug]));
        $consumption->assertOk()
            ->assertSee(__('admin.consumption_col_credit'))
            ->assertSee(__('ai.credit_used_of_quota', ['used' => 5, 'quota' => 5]))
            ->assertDontSee('Membre Beta');

        // Le SuperAdmin voit des COMPTES par Organization : 1 proche du seuil, 1 au plafond chez A ; 1 au plafond chez B.
        $platform = $this->actingAs($this->superAdmin)->get(route('admin.ai-monetization'));
        $platform->assertOk()
            ->assertSee('data-ai-monetization-organization="'.$this->orgA->slug.'" data-ai-monetization-alerting="1" data-ai-monetization-blocked="1"', false)
            ->assertSee('data-ai-monetization-organization="'.$this->orgB->slug.'" data-ai-monetization-alerting="0" data-ai-monetization-blocked="1"', false)
            ->assertDontSee('Membre Alpha Un')
            ->assertDontSee('Membre Beta');
    }

    public function test_the_credit_status_and_pages_never_carry_a_credential(): void
    {
        $this->platform(monthlyUses: 3);
        $this->uses($this->memberA, 1);

        $json = json_encode($this->guard()->userCreditStatus($this->orgA, $this->memberA)->toArray());
        $this->assertStringNotContainsString('sk-or-task1229', $json);

        foreach ([
            $this->actingAs($this->memberA)->get(route('organization.profile.ai-usage', ['organization' => $this->orgA->slug])),
            $this->actingAs($this->adminA)->get(route('organization.admin.ai', ['organization' => $this->orgA->slug])),
            $this->actingAs($this->superAdmin)->get(route('admin.ai-monetization')),
        ] as $response) {
            $response->assertOk()->assertDontSee('sk-or-task1229');
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function settings(): AiUserCreditSettings
    {
        return app(AiUserCreditSettings::class);
    }

    private function guard(): AiEconomicGuard
    {
        return app(AiEconomicGuard::class);
    }

    private function platform(?int $monthlyUses, bool $freeEnabled = true, int $alertPercent = 80, bool $offerSubscription = true): void
    {
        $this->settings()->updatePlatform([
            'free_enabled' => $freeEnabled,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => $alertPercent,
            'offer_subscription' => $offerSubscription,
        ], $this->superAdmin);
    }

    private function guardVerdict(User $user, ?Organization $organization = null): AiEconomicVerdict
    {
        $organization ??= $this->orgA;
        $organization->unsetRelation('aiSetting');

        return $this->guard()->authorize($organization, 'loop_knowledge.answer', 'openrouter', 'openai/gpt-4o-mini', 2.00, 10, $user);
    }

    /**
     * N utilisations creditees (generations productives) pour un utilisateur.
     */
    private function uses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->generation($user->organization, $user, cost: 0.001);
        }
    }

    private function generation(
        Organization $organization,
        User $user,
        ?float $cost,
        string $feature = 'loop_knowledge_answer',
        string $process = 'loop_knowledge.answer',
    ): AiInteraction {
        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'capability' => $feature,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);

        return AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => $process,
            'feature' => $feature,
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $cost,
            'cost_unknown' => $cost === null,
            'metadata' => ['provider' => 'openrouter', 'status' => 'success', 'capability' => $feature],
        ]);
    }

    private function embedding(Organization $organization, User $user, ?string $operation, ?float $cost): AiProviderInvocation
    {
        return AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'capability' => 'loop_knowledge_answer',
            'process' => $operation === 'query' ? 'dossier.embeddings_search' : 'dossier.embeddings_index',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING,
            'embedding_operation' => $operation,
            'provider' => 'openrouter',
            'model' => 'openai/text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'total_tokens' => 30,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function fakeKnowledgeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $label): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->dossier->id,
            'dossier_name' => $this->dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label} : une installation itinérante tient dans une valise.",
            'distance' => 0.2,
        ];
    }
}

/**
 * Double du moteur pgvector : renvoie des lignes canoniques et enregistre le
 * perimetre demande (`null` = jamais appele).
 */
class Task1229FakeSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, int $limit = 5, ?string $embeddingInstance = null, array $traceMetadata = []): array
    {
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'limit', 'embeddingInstance', 'traceMetadata');

        return array_slice($this->rows, 0, $limit);
    }
}
