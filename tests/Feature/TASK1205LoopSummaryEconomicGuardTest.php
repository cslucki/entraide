<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TASK1205LoopSummaryEconomicGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $loops = new LoopService;
        $this->loop = $loops->createLoop($owner, 'Economic guard loop');
        $loops->addMember($this->loop, $this->member, 'member');

        AiConfig::set('default_provider', 'openrouter');
        AiConfig::set('default_model', 'deepseek/deepseek-chat-v3-0324');
        config([
            'ai.openrouter.api_key' => 'test-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.chatloop.min_summary_words' => 0,
            'ai.chatloop.summary_economic_guard.monthly_budget_usd' => 2.00,
            'ai.chatloop.summary_economic_guard.monthly_unknown_limit' => 10,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    public function test_openrouter_reported_cost_is_persisted_without_changing_provider_model_or_process(): void
    {
        Http::fake(['openrouter.test/*' => Http::response($this->response(['cost' => 0.012345]))]);

        $message = app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $interaction = AiInteraction::findOrFail($message->metadata['ai_interaction_id']);

        $this->assertSame('openrouter/deepseek/deepseek-chat-v3-0324', $interaction->model);
        $this->assertSame('chatloop.summarize', $interaction->process);
        $this->assertSame('0.012345', (string) $interaction->cost_usd);
        $this->assertFalse($interaction->cost_unknown);
        $this->assertSame($this->organization->id, $interaction->organization_id);

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'deepseek/deepseek-chat-v3-0324');
    }

    public function test_missing_reported_cost_uses_catalog_then_preserves_unknown_when_rate_is_absent(): void
    {
        config(['ai_pricing.models.openrouter.deepseek/deepseek-chat-v3-0324' => [
            'input_per_1m' => 1.0,
            'output_per_1m' => 2.0,
        ]]);
        Http::fake(['openrouter.test/*' => Http::response($this->response())]);

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $known = AiInteraction::latest('created_at')->firstOrFail();
        $this->assertSame('0.000050', (string) $known->cost_usd);
        $this->assertFalse($known->cost_unknown);

        config(['ai_pricing.models.openrouter' => []]);
        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $unknown = AiInteraction::latest('created_at')->firstOrFail();
        $this->assertNull($unknown->cost_usd);
        $this->assertTrue($unknown->cost_unknown);
    }

    public function test_reported_zero_is_known(): void
    {
        Http::fake(['openrouter.test/*' => Http::response($this->response(['cost' => 0]))]);

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $interaction = AiInteraction::firstOrFail();

        $this->assertSame('0.000000', (string) $interaction->cost_usd);
        $this->assertFalse($interaction->cost_unknown);
    }

    public function test_provider_reported_cost_pilot_does_not_change_other_chatloop_operations(): void
    {
        Http::fake(['openrouter.test/*' => Http::response($this->response(['cost' => 0.50]))]);

        app(ChatLoopAiService::class)->answer($this->loop, $this->member);
        $interaction = AiInteraction::firstOrFail();

        $this->assertSame('chatloop.answer', $interaction->process);
        $this->assertNull($interaction->cost_usd);
        $this->assertTrue($interaction->cost_unknown);
    }

    public function test_budget_refusal_does_not_call_provider_and_does_not_count_other_tenants_or_processes(): void
    {
        $other = Organization::factory()->create();
        $this->interaction($other, 'chatloop.summarize', 10.00, false);
        $this->interaction($this->organization, 'chatloop.answer', 10.00, false);
        Http::fake(['openrouter.test/*' => Http::response($this->response(['cost' => 0.01]))]);

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        Http::assertSentCount(1);

        $this->interaction($this->organization, 'chatloop.summarize', 2.00, false);
        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('Budget refusal expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('loops.ai_summary_monthly_budget_reached'), $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_unknown_quota_refusal_does_not_call_provider(): void
    {
        foreach (range(1, 10) as $unused) {
            $this->interaction($this->organization, 'chatloop.summarize', null, true);
        }
        Http::fake(['openrouter.test/*' => Http::response($this->response())]);

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('Unknown quota refusal expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('loops.ai_summary_temporarily_unavailable'), $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_provider_exception_does_not_create_an_interaction_or_cost(): void
    {
        Http::fake(['openrouter.test/*' => Http::failedConnection()]);

        $this->expectException(\RuntimeException::class);

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        } finally {
            $this->assertDatabaseCount('ai_interactions', 0);
        }
    }

    private function response(array $usageOverrides = []): array
    {
        return [
            'choices' => [['message' => ['content' => 'Résumé économique visible.']]],
            'usage' => array_merge([
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
            ], $usageOverrides),
        ];
    }

    private function interaction(Organization $organization, string $process, ?float $cost, ?bool $unknown): void
    {
        $user = User::factory()->create(['organization_id' => $organization->id]);

        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'process' => $process,
            'feature' => 'chatloop_ai_summarize',
            'model' => 'openrouter/deepseek/deepseek-chat-v3-0324',
            'prompt' => 'prompt',
            'response' => 'response',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => $cost,
            'cost_unknown' => $unknown,
        ]);
    }
}
