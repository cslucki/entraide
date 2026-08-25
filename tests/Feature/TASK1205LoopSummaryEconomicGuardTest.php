<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopSummaryAgent;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * Garde economique P2 de `loop_summary` (TASK-1205), portee sur le chemin
 * Laravel AI SDK de TASK-1207.
 *
 * Ce qui change avec la migration : laravel/ai v0.7.2 n'expose AUCUN cout
 * provider (ni `Usage`, ni `Meta` — verifie dans `vendor/`). La priorite 1
 * « cout provider-reporte » n'a donc plus de source, et le catalogue devient
 * le premier echelon effectif. Les deux echelons suivants sont inchanges.
 *
 * Ce qui ne change pas : le budget, le quota UNKNOWN, l'isolation par
 * Organization, et le fait qu'un refus n'emette aucun appel.
 */
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
        // TASK-1212 : provider/modele/credential portes par l'Organization.
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'deepseek/deepseek-chat-v3-0324']);
        $owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $loops = new LoopService;
        $this->loop = $loops->createLoop($owner, 'Economic guard loop');
        $loops->addMember($this->loop, $this->member, 'member');

        AiConfig::set('default_provider', 'openrouter');
        AiConfig::set('default_model', 'deepseek/deepseek-chat-v3-0324');
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'test-key',
            'ai.chatloop.min_summary_words' => 0,
            'ai.chatloop.summary_economic_guard.monthly_budget_usd' => 2.00,
            'ai.chatloop.summary_economic_guard.monthly_unknown_limit' => 10,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    public function test_catalog_cost_is_persisted_without_changing_provider_model_or_process(): void
    {
        config(['ai_pricing.models.openrouter.deepseek/deepseek-chat-v3-0324' => [
            'input_per_1m' => 1.0,
            'output_per_1m' => 2.0,
        ]]);
        $this->fakeSummary();

        $summary = app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $interaction = AiInteraction::findOrFail($summary->aiInteractionId);

        $this->assertSame('openrouter/deepseek/deepseek-chat-v3-0324', $interaction->model);
        $this->assertSame('chatloop.summarize', $interaction->process);
        $this->assertSame('0.000050', (string) $interaction->cost_usd);
        $this->assertFalse($interaction->cost_unknown);
        $this->assertSame($this->organization->id, $interaction->organization_id);

        LoopSummaryAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === 'deepseek/deepseek-chat-v3-0324'
                && $prompt->provider->name() === 'org:'.$this->organization->id.':openrouter'
        );
    }

    public function test_absent_catalog_rate_preserves_unknown_instead_of_inventing_zero(): void
    {
        config(['ai_pricing.models.openrouter' => []]);
        $this->fakeSummary();

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $unknown = AiInteraction::latest('created_at')->firstOrFail();

        $this->assertNull($unknown->cost_usd);
        $this->assertTrue($unknown->cost_unknown);
    }

    public function test_usage_not_reported_by_the_sdk_stays_unknown(): void
    {
        config(['ai_pricing.models.openrouter.deepseek/deepseek-chat-v3-0324' => [
            'input_per_1m' => 1.0,
            'output_per_1m' => 2.0,
        ]]);

        // Les passerelles du SDK ecrivent `$usage['prompt_tokens'] ?? 0` : un
        // bloc `usage` absent devient 0/0. Ce couple ne peut pas etre une vraie
        // generation, il doit rester UNKNOWN et non produire un cout de 0.
        $this->fakeSummary(promptTokens: 0, completionTokens: 0);

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $interaction = AiInteraction::firstOrFail();

        $this->assertNull($interaction->cost_usd);
        $this->assertTrue($interaction->cost_unknown);
        $this->assertSame(0, $interaction->input_tokens);
        $this->assertSame(0, $interaction->output_tokens);
    }

    public function test_a_free_catalog_rate_is_a_known_zero(): void
    {
        config(['ai_pricing.models.openrouter.deepseek/deepseek-chat-v3-0324' => [
            'input_per_1m' => 0.0,
            'output_per_1m' => 0.0,
            'free' => true,
        ]]);
        $this->fakeSummary();

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $interaction = AiInteraction::firstOrFail();

        $this->assertSame('0.000000', (string) $interaction->cost_usd);
        $this->assertFalse($interaction->cost_unknown);
    }

    public function test_the_sdk_migration_does_not_change_other_chatloop_operations(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => "Réponse de l'IA."]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ])]);
        config(['ai.openrouter.api_key' => 'test-key']);

        app(ChatLoopAiService::class)->answer($this->loop, $this->member);
        $interaction = AiInteraction::firstOrFail();

        // `answer()` reste sur le chemin HTTP direct : ni SDK, ni changement de
        // process, ni changement de cout.
        $this->assertSame('chatloop.answer', $interaction->process);
        $this->assertNull($interaction->cost_usd);
        $this->assertTrue($interaction->cost_unknown);
        LoopSummaryAgent::assertNeverPrompted();
    }

    public function test_budget_refusal_does_not_call_the_sdk_and_ignores_other_tenants_or_processes(): void
    {
        $other = Organization::factory()->create();
        $this->interaction($other, 'chatloop.summarize', 10.00, false);
        $this->interaction($this->organization, 'chatloop.answer', 10.00, false);
        config(['ai_pricing.models.openrouter.deepseek/deepseek-chat-v3-0324' => [
            'input_per_1m' => 1.0,
            'output_per_1m' => 2.0,
        ]]);
        $this->fakeSummary();

        // Ni l'autre Organization, ni l'autre process ne comptent : l'appel passe.
        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
        $this->assertSame(1, $this->promptCount());

        $this->interaction($this->organization, 'chatloop.summarize', 2.00, false);

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('Budget refusal expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('loops.ai_summary_monthly_budget_reached'), $exception->getMessage());
        }

        $this->assertSame(1, $this->promptCount());
    }

    public function test_unknown_quota_refusal_does_not_call_the_sdk(): void
    {
        foreach (range(1, 10) as $unused) {
            $this->interaction($this->organization, 'chatloop.summarize', null, true);
        }
        $this->fakeSummary();

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('Unknown quota refusal expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('loops.ai_summary_temporarily_unavailable'), $exception->getMessage());
        }

        LoopSummaryAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    /**
     * TASK-1207 : un echec SDK est desormais TRACE, la ou le chemin legacy le
     * perdait. Il ne doit pour autant inventer aucun cout, ni peser sur la
     * garde economique : `cost_usd` NULL et `cost_unknown` NULL, l'etat
     * « statut de cout non evalue » du tri-etat P1-2.
     */
    public function test_sdk_exception_is_traced_without_inventing_a_cost_or_consuming_the_quota(): void
    {
        LoopSummaryAgent::fake(function (): never {
            throw new \RuntimeException('Provider is unreachable.');
        });

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('SDK failure expected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('loops.ai_error'), $exception->getMessage());
        }

        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('chatloop.summarize', $interaction->process);
        $this->assertSame($this->organization->id, $interaction->organization_id);
        $this->assertNull($interaction->response);
        $this->assertNull($interaction->cost_usd);
        $this->assertNull($interaction->cost_unknown);
        $this->assertSame('failed', $interaction->metadata['status']);

        // Ni budget consomme, ni quota UNKNOWN entame : les deux compteurs de
        // `AiEconomicGuard` filtrent sur `cost_unknown` false/true, jamais null.
        $this->assertSame(0, AiInteraction::where('cost_unknown', true)->count());
        $this->assertSame(0.0, (float) AiInteraction::where('cost_unknown', false)->sum('cost_usd'));

        // Un echec ne peut pas devenir « le dernier resume ».
        $this->assertNull(app(ChatLoopAiService::class)->latestSummary($this->loop));
    }

    private function fakeSummary(int $promptTokens = 10, int $completionTokens = 20): void
    {
        LoopSummaryAgent::fake([
            new TextResponse(
                'Résumé économique visible.',
                new Usage($promptTokens, $completionTokens),
                new Meta('openrouter', 'deepseek/deepseek-chat-v3-0324'),
            ),
        ]);
    }

    private function promptCount(): int
    {
        $count = 0;

        LoopSummaryAgent::assertPrompted(function () use (&$count): bool {
            $count++;

            return true;
        });

        return $count;
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

        // TASK-1260 : jumelle ledger — l'autorite generation de la garde
        // depuis le cutover ; le monde reel ecrit les deux tables ensemble.
        AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'process' => $process,
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'deepseek/deepseek-chat-v3-0324',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $unknown === true ? null : $cost,
            'currency' => $unknown === true ? null : 'USD',
            'cost_status' => $unknown === true ? AiProviderInvocation::COST_UNKNOWN : AiProviderInvocation::COST_KNOWN,
            'cost_source' => $unknown === true ? 'unknown' : 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
