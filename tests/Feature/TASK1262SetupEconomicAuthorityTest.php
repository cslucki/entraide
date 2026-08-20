<?php

namespace Tests\Feature;

use App\Livewire\MemberAiProfileConversationalSetup;
use App\Models\AdminAiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Services\Ai\SupervisionEconomicScope;
use App\Support\Ai\AiRefusedException;
use Carbon\CarbonImmutable;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('ai')]
#[Group('sensitive')]
class TASK1262SetupEconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private Organization $elsewhere;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'task1262',
            'ai_pricing.overrides' => [],
            'ai_pricing.models.openrouter.router/catalogued' => ['input_per_1m' => 2.0, 'output_per_1m' => 4.0],
            'ai.supervision_resolver.economic_guard.monthly_budget_usd' => 2.0,
            'ai.supervision_resolver.economic_guard.monthly_unknown_limit' => 10,
            'ai.default_provider' => 'openrouter',
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'platform-key',
            'ai.openrouter.base_url' => 'https://openrouter.task1262/api/v1',
            'ai.openrouter.model' => 'router/catalogued',
            'ai.openrouter.models' => ['router/catalogued' => 'Catalogued'],
            'ai.openai.supervision_enabled' => false,
            'ai.ollama.enabled' => false,
        ]);

        $this->tenant = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->elsewhere = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->tenant->id]);
        $this->actingAs($this->member);
        app()->instance('current_organization', $this->tenant);
        $this->seed(AiPromptSeeder::class);
        Http::preventStrayRequests();
    }

    private function fakeSuccess(?array $usage = ['prompt_tokens' => 120, 'completion_tokens' => 30]): void
    {
        Http::fake([
            'openrouter.task1262/*' => Http::response(array_filter([
                'choices' => [['message' => ['content' => 'Présentez votre activité.']]],
                'usage' => $usage,
            ], fn ($value) => $value !== null)),
        ]);
    }

    private function scope(Organization $organization): SupervisionEconomicScope
    {
        return new SupervisionEconomicScope($organization, $this->member, $this->member, 'member_profile_agent_setup');
    }

    public function test_success_records_canonical_attribution_observed_usage_and_admin_trace(): void
    {
        $this->fakeSuccess();

        $from = CarbonImmutable::now()->startOfMonth();
        $to = $from->addMonth();
        $usage = app(OrganizationAiEconomicUsage::class);
        $creditBefore = $usage->userCreditUses($this->tenant->id, $from, $to, $this->member->id);
        $budgetBefore = $usage->summary($this->tenant->id, $from, $to);

        Livewire::test(MemberAiProfileConversationalSetup::class)->call('start');

        Http::assertSentCount(1);
        $line = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $line->status);
        $this->assertSame('member_profile.agent_setup', $line->process);
        $this->assertSame('member_profile_agent_setup', $line->feature);
        $this->assertNull($line->capability);
        $this->assertSame($this->tenant->id, $line->organization_id);
        $this->assertSame($this->member->id, $line->user_id);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $line->credential_source);
        $this->assertSame(120, $line->input_tokens);
        $this->assertSame(30, $line->output_tokens);

        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame('success', $trace->status);
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertSame(120, $trace->input_tokens);
        $this->assertSame(30, $trace->output_tokens);
        // T1261 devra revoir le credit (CREDITABLE = YES) ; le budget restera inchange,
        // car member_profile.agent_setup reste hors LEDGER_AUTHORITY_PROCESSES.
        $this->assertSame($creditBefore, $usage->userCreditUses($this->tenant->id, $from, $to, $this->member->id));
        $this->assertSame($budgetBefore, $usage->summary($this->tenant->id, $from, $to));
    }

    public function test_absent_usage_is_not_observed_never_invented_as_zero(): void
    {
        $this->fakeSuccess(null);

        Livewire::test(MemberAiProfileConversationalSetup::class)->call('start');

        $line = AiProviderInvocation::query()->firstOrFail();
        $this->assertNull($line->input_tokens);
        $this->assertNull($line->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $line->cost_status);
    }

    public function test_provider_failure_records_failed_ledger_and_admin_trace_with_class_only(): void
    {
        Http::fake(['openrouter.task1262/*' => Http::response(['error' => 'secret upstream detail'], 500)]);

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->assertSet('economicRefused', false)
            ->assertSet('error', 'Impossible de démarrer la conversation. Vérifiez la configuration IA.')
            ->assertSet('errorCode', null)
            ->assertSee(__('ai.setup_restart'))
            ->assertSee('bg-red-50', false)
            ->assertDontSee('bg-amber-50', false)
            ->assertDontSee('data-ai-refusal-code=', false);

        $line = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $line->status);
        $this->assertSame(\RuntimeException::class, $line->failure_reason);
        $this->assertStringNotContainsString('secret', $line->failure_reason);
        $trace = AdminAiInteraction::query()->firstOrFail();
        $this->assertSame('failed', $trace->status);
        $this->assertSame($this->tenant->id, $trace->organization_id);
        $this->assertSame(\RuntimeException::class, $trace->metadata['provider_failure']);
    }

    public function test_refusal_on_start_has_no_provider_or_trace_and_removes_retry_affordance(): void
    {
        $refused = new AiRefusedException(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, 'Budget atteint.');
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')->once()->andThrow($refused)
            ->shouldNotReceive('logSetupInteraction');

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->assertSet('economicRefused', true)
            ->assertSet('errorCode', AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED)
            ->assertSee('data-ai-refusal-code="'.AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED.'"', false)
            ->assertSee('bg-amber-50', false)
            ->assertDontSee('bg-red-50', false)
            ->assertDontSee(__('ai.setup_restart'))
            ->assertDontSee(__('ai.setup_send'))
            ->assertDontSee(__('ai.setup_max_turns_reached'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_provider_invocations', 0);
        $this->assertDatabaseCount('admin_ai_interactions', 0);
    }

    public function test_real_max_turns_still_displays_the_limit_message_without_refusal(): void
    {
        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->set('started', true)
            ->set('turnCount', MemberAiProfileConversationalSetup::MAX_TURNS)
            ->set('economicRefused', false)
            ->assertSee(__('ai.setup_max_turns_reached'));
    }

    public function test_post_provider_failure_does_not_create_a_false_failed_admin_trace(): void
    {
        $this->fakeSuccess();
        $realResponder = app(MemberProfileAgentResponder::class);

        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')
            ->once()
            ->andReturnUsing(fn (array $messages, string $provider, string $model, SupervisionEconomicScope $scope) => $realResponder->chatWithSetupPrompt($messages, $provider, $model, $scope))
            ->shouldReceive('logSetupInteraction')
            ->once()
            ->andThrow(new \RuntimeException('post-provider persistence failure'));

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->assertSet('economicRefused', false)
            ->assertSet('error', 'Impossible de démarrer la conversation. Vérifiez la configuration IA.');

        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, AiProviderInvocation::query()->firstOrFail()->status);
        $this->assertDatabaseMissing('admin_ai_interactions', ['status' => 'failed']);
    }

    public function test_refusal_on_send_does_not_increment_turn_or_write_traces(): void
    {
        $refused = new AiRefusedException(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, 'Crédit épuisé.');
        $this->mock(MemberProfileAgentResponder::class)
            ->shouldReceive('chatWithSetupPrompt')->once()->andReturn([
                'response' => 'Bonjour', 'provider' => 'openrouter', 'model' => 'router/catalogued', 'latency_ms' => 1,
            ])
            ->shouldReceive('logSetupInteraction')->once()
            ->shouldReceive('chatWithSetupPrompt')->once()->andThrow($refused);

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('currentInput', 'Mon activité')
            ->call('send')
            ->assertSet('turnCount', 0)
            ->assertSet('economicRefused', true)
            ->assertSet('errorCode', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);
    }

    public function test_profile_tenant_wins_over_current_organization(): void
    {
        $profile = MemberAiProfile::factory()->create([
            'organization_id' => $this->tenant->id,
            'user_id' => $this->member->id,
        ]);
        app()->instance('current_organization', $this->elsewhere);
        $this->fakeSuccess();

        $result = app(MemberProfileAgentResponder::class)->chatWithSetupPrompt(
            [['role' => 'user', 'content' => 'Bonjour']],
            'openrouter',
            'router/catalogued',
            $this->scope($profile->load('organization')->organization),
        );
        app(MemberProfileAgentResponder::class)->logSetupInteraction('Bonjour', $result['response'], $result, $profile, $profile->organization_id);

        $this->assertSame($this->tenant->id, AiProviderInvocation::query()->firstOrFail()->organization_id);
        $this->assertSame($this->tenant->id, AdminAiInteraction::query()->firstOrFail()->organization_id);
    }

    public function test_pre_creation_ledger_tenant_equals_profile_created_after_validation(): void
    {
        $this->fakeSuccess();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('previewData', ['summary' => 'Résumé', 'service_scope' => 'Conseil', 'skills' => ['PHP']])
            ->set('showPreview', true)
            ->call('validateAndSave');

        $ledgerOrganizationId = AiProviderInvocation::query()->firstOrFail()->organization_id;
        $profileOrganizationId = MemberAiProfile::query()->where('user_id', $this->member->id)->firstOrFail()->organization_id;
        $this->assertSame($profileOrganizationId, $ledgerOrganizationId);
    }
}
