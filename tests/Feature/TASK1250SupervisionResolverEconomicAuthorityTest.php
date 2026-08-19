<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminAiSupervisionController;
use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Category;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1250 — fermer l'autorite economique des trois chemins AUTHENTIFIES
 * passant par `SupervisionProviderResolver` (famille C du gap analysis T1246) :
 *
 *   #13 `ServiceController::formulate()`            (membre, credit applique)
 *   #17 `AdminMemberAiProfileController::testLlm()` (admin, tenant = Organization du profil)
 *   #18 `AdminAiSupervisionController::analyze()`   (SuperAdmin, tenant = Organization plateforme)
 *
 * Pour chacun : garde AVANT provider (rien ne part, rien ne s'ecrit sur
 * refus, reponse STRUCTUREE 429 + code), credential PROUVE `platform`
 * (declare, jamais deduit), ledger canonique `ai_provider_invocations` sur
 * chaque appel tente (succes ET echec, cout catalogue ou unknown, jamais 0
 * invente), tenant explicite et correct, zero double comptage.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1250SupervisionResolverEconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** Organization PLATEFORME (`is_default`) : tenant de record du banc SuperAdmin. */
    private Organization $platform;

    /** Organization d'un tenant ordinaire (celle du membre et de son offre). */
    private Organization $tenant;

    /** Organization personnelle de l'administrateur plateforme — jamais un payeur. */
    private Organization $adminHome;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openai' => [
                    'gpt-catalogued' => ['input_per_1m' => 1.0, 'output_per_1m' => 4.0],
                ],
                'openrouter' => [
                    'router/catalogued' => ['input_per_1m' => 2.0, 'output_per_1m' => 2.0],
                ],
                'ollama' => [
                    '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                ],
            ],
            'ai.supervision.enabled' => true,
            'ai.supervision_resolver.economic_guard.monthly_budget_usd' => 2.00,
            'ai.supervision_resolver.economic_guard.monthly_unknown_limit' => 10,

            'ai.default_provider' => 'openrouter',
            'ai.default_model' => null,

            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'platform-openrouter-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.model' => 'router/catalogued',
            'ai.openrouter.timeout' => 15,
            'ai.openrouter.max_output_tokens' => 900,

            'ai.openai.supervision_enabled' => true,
            'ai.openai.api_key' => 'platform-openai-key',
            'ai.openai.base_url' => 'https://api.openai.test/v1',
            'ai.openai.model' => 'gpt-catalogued',
            'ai.openai.max_output_tokens' => 900,
            'ai.openai.timeout' => 15,

            'ai.ollama.enabled' => false,
        ]);

        // L'Organization plateforme en premier : `UserFactory` attache par
        // defaut a `DefaultOrganizationResolver` — on passe toujours l'org
        // explicitement ci-dessous, mais l'ordre rend la lecture honnete.
        $this->platform = Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1250']);
        $this->tenant = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-1250', 'service_points_min' => 20, 'service_points_max' => 200]);
        $this->adminHome = Organization::factory()->create(['is_active' => true, 'slug' => 'admin-home-1250']);

        Category::factory()->create(['organization_id' => $this->tenant->id, 'name_b2c' => 'Developpement Web', 'slug' => 'dev-web-1250']);

        $this->member = User::factory()->create([
            'organization_id' => $this->tenant->id,
            'first_name' => 'Jean',
            'phone' => '0123456789',
            'city' => 'Paris',
            'country_code' => 'FR',
            'bio' => 'Bio complete pour le middleware profile.complete.',
            'preferred_locale' => 'fr',
        ]);
        $this->admin = User::factory()->create(['organization_id' => $this->adminHome->id, 'is_admin' => true]);

        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------------
    // Fakes provider
    // ------------------------------------------------------------------

    /** OpenRouter `chat/completions` — un JSON de scenario, avec ou sans usage. */
    private function fakeOpenRouterScenario(array $payload, ?array $usage = ['prompt_tokens' => 300, 'completion_tokens' => 150]): void
    {
        Http::fake([
            'openrouter.test/*' => Http::response(array_filter([
                'model' => 'router/catalogued',
                'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($payload)]]],
                'usage' => $usage,
            ])),
        ]);
    }

    /** OpenAI Responses API — le banc SuperAdmin. */
    private function fakeOpenAiResponses(array $payload, int $inputTokens = 120, int $outputTokens = 80): void
    {
        Http::fake([
            'api.openai.test/*' => Http::response([
                'id' => 'resp_1250',
                'object' => 'response',
                'status' => 'completed',
                'model' => 'gpt-catalogued',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($payload)]],
                ]],
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            ]),
        ]);
    }

    private function serviceOfferPayload(): array
    {
        return [
            'title' => 'Developpement de site vitrine en Laravel',
            'description_markdown' => "Je vous propose un developpement complet de site vitrine avec Laravel.\n\n## Ce que je propose\n\n- Analyse de vos besoins\n- Design responsive\n- Mise en ligne",
            'category_id' => Category::where('organization_id', $this->tenant->id)->value('id'),
            'delivery_mode' => 'remote',
            'points_cost' => 50,
        ];
    }

    private function supervisionPayload(): array
    {
        return [
            'summary' => 'Message neutre demandant de l\'aide.',
            'risk_level' => 'low',
            'category' => ['slug' => 'redaction', 'label' => 'Rédaction'],
            'skills' => [],
            'unmatched_terms' => [],
            'needs_human_category_review' => false,
            'category_review_reason' => '',
            'recommendations' => ['Laisser passer.'],
            'moderation_flag' => false,
            'notes' => 'Contenu acceptable.',
        ];
    }

    private function clarifyPayload(): array
    {
        return [
            'title' => 'Aide pour rédaction CV',
            'clarified_request' => 'Le membre cherche de l\'aide pour rédiger un CV professionnel.',
            'help_type' => 'service_offer',
            'suggested_category' => 'redaction',
            'suggested_loop' => 'Rédaction pro',
            'questions_for_user' => [],
            'publishable_draft' => 'Je propose mon aide pour la rédaction de CV.',
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Requetes
    // ------------------------------------------------------------------

    private function formulate(?User $as = null)
    {
        app()->instance('current_organization', $this->tenant);

        return $this->actingAs($as ?? $this->member)
            ->postJson('/services/ai-formulate', [
                'title' => 'Je fais des sites web',
                'description' => 'Je peux creer des sites internet pour votre entreprise.',
            ]);
    }

    private function bench(string $scenario = 'supervision_content', string $provider = 'openai', string $model = 'gpt-catalogued')
    {
        return $this->actingAs($this->admin)->post(route('admin.ai-supervision.analyze'), [
            'content' => 'J\'aimerais aider quelqu\'un cette semaine a rediger son CV.',
            'provider' => $provider,
            'model' => $model,
            'scenario' => $scenario,
        ]);
    }

    private function makeProfile(): MemberAiProfile
    {
        $owner = User::factory()->create(['organization_id' => $this->tenant->id]);

        return MemberAiProfile::factory()->create([
            'organization_id' => $this->tenant->id,
            'user_id' => $owner->id,
            'status' => MemberAiProfile::STATUS_PENDING_VALIDATION,
            'service_scope' => 'Audit SEO local',
            'member_profile_summary' => 'Consultant SEO',
        ]);
    }

    private function runLlmTest(MemberAiProfile $profile, string $provider = 'openrouter', string $model = 'router/catalogued')
    {
        return $this->actingAs($this->admin)->post(route('admin.member-ai-profiles.test-llm', $profile), [
            'provider' => $provider,
            'model' => $model,
            'question' => "C'est quoi ta prestation ?",
        ]);
    }

    // ------------------------------------------------------------------
    // Fixtures economiques
    // ------------------------------------------------------------------

    private function exhaustPlatformCredit(): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 0, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->admin);
    }

    /** Budget Organization atteint : plafond minuscule + une depense connue ce mois-ci (autre chemin). */
    private function reachOrganizationBudget(Organization $organization, User $spender): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-catalogued',
            'api_key' => 'sk-tenant',
            'monthly_budget_usd' => 0.001,
        ]);
        AiInteraction::create([
            'user_id' => $spender->id,
            'organization_id' => $organization->id,
            'process' => 'blog.article_generate',
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => 0.5,
            'cost_unknown' => false,
            'metadata' => [],
        ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Un refus n\'ecrit aucune ligne de ledger.');
        $this->assertSame(0, AdminAiInteraction::query()->count(), 'Un refus n\'ecrit aucune trace operationnelle.');
        $this->assertSame(0, AiInteraction::query()->count(), 'Ces chemins n\'ecrivent jamais de trace produit.');
        Http::assertNothingSent();
    }

    // =====================================================================
    // A. #13 — formulation d'offre de service (membre, credit applique)
    // =====================================================================

    public function test_a_service_offer_formulation_writes_one_platform_ledger_line_on_the_current_organization(): void
    {
        $this->fakeOpenRouterScenario($this->serviceOfferPayload());

        $this->formulate()->assertOk()->assertJsonPath('suggestion.title', 'Developpement de site vitrine en Laravel');
        Http::assertSentCount(1);

        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger, 'Un appel = une ligne de ledger, jamais deux.');
        $row = $ledger->first();
        $this->assertSame($this->tenant->id, $row->organization_id, 'Tenant = Organization courante, celle de l\'offre.');
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertNull($row->capability, 'Pas une capability canonique : dit tel quel.');
        $this->assertSame('service_offer_formulation', $row->feature);
        $this->assertSame('service_offer.master', $row->process);
        $this->assertSame(AiProviderInvocation::OPERATION_GENERATION, $row->operation);
        $this->assertSame('openrouter', $row->provider);
        $this->assertSame('router/catalogued', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source, 'Cle plateforme DECLAREE, jamais deduite.');
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        // `runScenario()` ne rapporte aucun usage (contrat du provider) : on
        // n'invente ni tokens ni cout — UNKNOWN, pas 0.
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertNotNull($row->correlation_id);

        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame(1, AdminAiInteraction::query()->count(), 'La trace operationnelle existante, une seule fois.');
        $this->assertSame('service_offer_master', $trace->scenario_id);
        $this->assertSame($this->tenant->id, $trace->organization_id, 'Meme tenant sur la trace operationnelle.');
        $this->assertSame($row->correlation_id, $trace->correlation_id, 'Meme correlation sur le ledger et la trace.');
        $this->assertSame('service_offer.master', $trace->process);
        $this->assertSame(0, AiInteraction::query()->count(), 'Aucune trace produit : zero double comptage.');
    }

    public function test_a_member_with_an_exhausted_credit_is_refused_before_any_call_with_a_structured_429(): void
    {
        Http::fake();
        $this->exhaustPlatformCredit();

        $response = $this->formulate();
        $response->assertStatus(429)
            ->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
            ->assertJsonStructure(['error', 'code', 'offers_url'])
            ->assertJsonMissingPath('suggestion');
        $this->assertIsString($response->json('offers_url'));

        $this->assertNothingWritten();
    }

    public function test_an_organization_budget_reached_refuses_the_formulation_before_any_call(): void
    {
        Http::fake();
        $this->reachOrganizationBudget($this->tenant, $this->member);

        $this->formulate()->assertStatus(429)
            ->assertJsonPath('code', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED)
            ->assertJsonMissingPath('suggestion');

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, AdminAiInteraction::query()->count());
    }

    public function test_the_process_budget_is_wired_on_service_offer_master(): void
    {
        // La garde lit le budget PAR PROCESS dans l'autorite actuelle
        // (`ai_interactions`) : une depense connue sur `service_offer.master`
        // ferme ce process, et lui seul.
        Http::fake();
        config(['ai.supervision_resolver.economic_guard.monthly_budget_usd' => 0.10]);
        AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->tenant->id,
            'process' => 'service_offer.master',
            'feature' => 'service_offer_formulation',
            'model' => 'openrouter/router/catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => 0.20,
            'cost_unknown' => false,
            'metadata' => [],
        ]);

        $this->formulate()->assertStatus(429)
            ->assertJsonPath('code', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED);
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_a_missing_platform_key_refuses_the_formulation_as_not_configured_before_any_call(): void
    {
        Http::fake();
        config(['ai.openrouter.api_key' => '']);

        $this->formulate()->assertStatus(429)
            ->assertJsonPath('code', AiRefusedException::CODE_NOT_CONFIGURED);

        $this->assertNothingWritten();
    }

    public function test_a_provider_failure_on_formulation_writes_a_failed_ledger_line_and_keeps_the_422_contract(): void
    {
        Http::fake(['openrouter.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->formulate()->assertStatus(422)->assertJsonPath('error', __('ai.service_formulation_error'));
        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(SupervisionException::class, $row->failure_reason);
        $this->assertNull($row->provider_cost, 'Jamais 0 invente sur un echec.');
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertNull($row->input_tokens);
        $this->assertSame('service_offer.master', $row->process);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame($this->tenant->id, $row->organization_id);

        // Inchange : la trace operationnelle n'est ecrite que sur succes —
        // l'echec vit au ledger, la seule table economique.
        $this->assertSame(0, AdminAiInteraction::query()->count());
    }

    // =====================================================================
    // B. #18 — banc de supervision SuperAdmin (tenant = Organization plateforme)
    // =====================================================================

    public function test_the_superadmin_bench_is_attributed_to_the_platform_organization_never_to_the_admin_one(): void
    {
        $this->fakeOpenAiResponses($this->supervisionPayload(), 120, 80);

        $this->bench()->assertOk()->assertSee('Message neutre demandant de l\'aide.');
        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame($this->platform->id, $row->organization_id, 'Tenant de record = Organization plateforme (is_default).');
        $this->assertNotSame($this->adminHome->id, $row->organization_id, 'Jamais l\'Organization personnelle de l\'admin.');
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertNull($row->capability);
        $this->assertSame(AdminAiSupervisionController::BENCH_FEATURE, $row->feature);
        $this->assertSame('supervision.content', $row->process);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-catalogued', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        // `supervise()` rapporte son usage : tokens observes, cout catalogue.
        $this->assertSame(120, (int) $row->input_tokens);
        $this->assertSame(80, (int) $row->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        // 120 x 1.0/1M + 80 x 4.0/1M
        $this->assertEqualsWithDelta(0.00044, (float) $row->provider_cost, 0.0000001);

        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame(1, AdminAiInteraction::query()->count());
        $this->assertSame($this->platform->id, $trace->organization_id, 'La trace operationnelle suit le meme tenant.');
        $this->assertSame($this->admin->id, $trace->user_id);
        $this->assertSame($row->correlation_id, $trace->correlation_id);
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_the_bench_clarify_scenario_is_under_the_same_authority_with_an_honest_unknown_cost(): void
    {
        $this->fakeOpenAiResponses($this->clarifyPayload());

        $this->bench('clarify_help_request')->assertOk()->assertSee('Aide pour rédaction CV');

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->platform->id, $row->organization_id);
        $this->assertSame('help_request.clarify', $row->process);
        $this->assertSame(AdminAiSupervisionController::BENCH_FEATURE, $row->feature);
        $this->assertNull($row->capability, 'Le banc n\'est pas la capability canonique clarify_help_request.');
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertNull($row->input_tokens, 'runScenario() n\'expose aucun usage : NULL, pas 0.');
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertSame('help_request.clarify', AdminAiInteraction::query()->value('process'));
    }

    public function test_the_bench_is_refused_with_a_structured_429_when_the_platform_organization_budget_is_reached(): void
    {
        Http::fake();
        $this->reachOrganizationBudget($this->platform, $this->admin);

        $response = $this->bench();
        $response->assertStatus(429)
            ->assertSee('data-economic-refusal="'.AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED.'"', false)
            ->assertDontSee('Message neutre');

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, AdminAiInteraction::query()->count());
    }

    public function test_the_bench_applies_no_user_credit_and_refuses_a_missing_platform_key_as_not_configured(): void
    {
        // Credit plateforme epuise pour tout le monde : le banc d'administration
        // n'est pas un usage credite (comme le bac a sable de doctrine).
        $this->fakeOpenAiResponses($this->supervisionPayload());
        $this->exhaustPlatformCredit();
        $this->bench()->assertOk();
        $this->assertSame(1, AiProviderInvocation::query()->count());

        // Cle plateforme absente : refus AVANT tout appel, code stable.
        config(['ai.openai.api_key' => '']);
        $this->bench()->assertStatus(429)
            ->assertSee('data-economic-refusal="'.AiRefusedException::CODE_NOT_CONFIGURED.'"', false);
        Http::assertSentCount(1);
        $this->assertSame(1, AiProviderInvocation::query()->count(), 'Le refus n\'a rien ecrit.');
    }

    public function test_a_bench_provider_failure_writes_a_failed_ledger_line_and_keeps_the_error_banner(): void
    {
        Http::fake(['api.openai.test/*' => Http::response(['error' => 'oops'], 500)]);

        $this->bench()->assertOk()->assertSee('Réponse OpenAI invalide');

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(SupervisionException::class, $row->failure_reason);
        $this->assertSame($this->platform->id, $row->organization_id);
        $this->assertSame(AdminAiSupervisionController::BENCH_FEATURE, $row->feature);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertSame(0, AdminAiInteraction::query()->count());
    }

    // =====================================================================
    // C. #17 — test LLM d'un profil par un admin (tenant = Organization du profil)
    // =====================================================================

    public function test_the_profile_llm_test_is_attributed_to_the_profile_organization_with_observed_usage_and_cost(): void
    {
        $profile = $this->makeProfile();
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Cette prestation porte sur l\'audit SEO local.']]],
                'usage' => ['prompt_tokens' => 1_000, 'completion_tokens' => 500],
            ]),
        ]);

        $this->runLlmTest($profile)->assertOk()->assertSee('Cette prestation porte sur l\'audit SEO local.');
        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame($this->tenant->id, $row->organization_id, 'Tenant = Organization du profil teste.');
        $this->assertNotSame($this->adminHome->id, $row->organization_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertNull($row->capability);
        $this->assertSame('member_ai_profile_llm_test', $row->feature);
        $this->assertSame('member_profile.admin_llm_test', $row->process);
        $this->assertSame('openrouter', $row->provider);
        $this->assertSame('router/catalogued', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertSame(1_000, (int) $row->input_tokens);
        $this->assertSame(500, (int) $row->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        // 1000 x 2.0/1M + 500 x 2.0/1M
        $this->assertEqualsWithDelta(0.003, (float) $row->provider_cost, 0.0000001);

        // La trace operationnelle existante porte desormais tokens et cout
        // (avant : colonnes vides, « non evalue »).
        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame(1, AdminAiInteraction::query()->count());
        $this->assertSame('member_ai_profile_llm_test', $trace->scenario_id);
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertSame('success', $trace->status);
        $this->assertSame(1_000, (int) $trace->input_tokens);
        $this->assertSame(500, (int) $trace->output_tokens);
        $this->assertFalse((bool) $trace->cost_unknown);
        $this->assertEqualsWithDelta(0.003, (float) $trace->cost_usd, 0.0000001);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $trace->metadata['credential_source'] ?? null);
        $this->assertSame($row->correlation_id, $trace->correlation_id);
        $this->assertSame(0, AiInteraction::query()->count());
    }

    public function test_the_profile_llm_test_on_ollama_declares_no_credential_and_a_real_zero_cost(): void
    {
        config([
            'ai.ollama.enabled' => true,
            'ai.ollama.base_url' => 'http://ollama.test',
            'ai.ollama.model' => 'ministral-3:3b',
        ]);
        $profile = $this->makeProfile();
        Http::fake([
            'ollama.test/*' => Http::response([
                'model' => 'ministral-3:3b',
                'response' => 'Je presente une prestation d\'audit SEO local.',
                'eval_count' => 42,
            ]),
        ]);

        $this->runLlmTest($profile, 'ollama', 'ministral-3:3b')->assertOk();

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame('ollama', $row->provider);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_NONE, $row->credential_source, 'Driver local sans cle : `none`, pas `platform`.');
        $this->assertNull($row->input_tokens, 'Ollama ne rapporte pas l\'entree : NULL, pas 0.');
        $this->assertSame(42, (int) $row->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status, 'Gratuit selon le catalogue : un VRAI zero.');
        $this->assertSame(0.0, (float) $row->provider_cost);
    }

    public function test_the_profile_llm_test_is_refused_on_the_profile_organization_budget_not_the_admin_one(): void
    {
        Http::fake();
        $profile = $this->makeProfile();
        // Le budget atteint est celui de l'Organization du PROFIL.
        $this->reachOrganizationBudget($this->tenant, $this->member);

        $this->runLlmTest($profile)->assertStatus(429)
            ->assertSee('data-economic-refusal="'.AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED.'"', false);

        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(0, AdminAiInteraction::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_profile_llm_test_ignores_the_admin_home_budget_and_applies_no_user_credit(): void
    {
        $profile = $this->makeProfile();
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Reponse.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);
        // Budget atteint sur l'Organization personnelle de l'admin : sans effet
        // (elle n'est pas le tenant de ce test) ; credit plateforme epuise :
        // sans effet (banc d'administration, aucun credit applique).
        $this->reachOrganizationBudget($this->adminHome, $this->admin);
        $this->exhaustPlatformCredit();

        $this->runLlmTest($profile)->assertOk();
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame($this->tenant->id, AiProviderInvocation::query()->value('organization_id'));
    }

    public function test_a_missing_platform_key_refuses_the_profile_llm_test_as_not_configured_before_any_call(): void
    {
        Http::fake();
        config(['ai.openrouter.api_key' => '']);
        $profile = $this->makeProfile();

        $this->runLlmTest($profile)->assertStatus(429)
            ->assertSee('data-economic-refusal="'.AiRefusedException::CODE_NOT_CONFIGURED.'"', false);

        $this->assertNothingWritten();
    }

    public function test_a_profile_llm_test_provider_failure_writes_a_failed_ledger_line_and_an_error_trace_without_cost(): void
    {
        $profile = $this->makeProfile();
        Http::fake(['openrouter.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->runLlmTest($profile)->assertOk()->assertSee('Réponse OpenRouter invalide (HTTP 500).');
        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(\RuntimeException::class, $row->failure_reason);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertSame($this->tenant->id, $row->organization_id);

        // La trace operationnelle d'echec existait deja : elle reste, avec la
        // convention des traces canoniques sur un echec (NULL / NULL).
        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame('error', $trace->status);
        $this->assertNull($trace->cost_usd);
        $this->assertNull($trace->cost_unknown);
        $this->assertSame(0, (int) $trace->input_tokens);
    }
}
