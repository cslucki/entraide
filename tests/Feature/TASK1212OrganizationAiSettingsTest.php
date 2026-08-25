<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopSummaryAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1212 — IA P4-lite : provider, modele, credential et budget portes par
 * l'Organization. Sans configuration tenant : aucun appel, aucun repli vers la
 * cle plateforme.
 */
class TASK1212OrganizationAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle P4');

        app()->instance('current_organization', $this->organization);

        // La plateforme a une cle d'environnement : elle ne doit JAMAIS servir
        // pour le compte d'un tenant.
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-env-key',
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-openai-key',
            'ai.chatloop.min_summary_words' => 0,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);
        AiConfig::set('clarification_enabled', true);
        AdminAiPrompt::query()->where('scenario_id', 'clarify_help_request')->where('version', 2)
            ->update(['prompt_text' => 'MARQUEUR PROMPT ADMIN CLARIFY.', 'is_active' => true]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // Resolution tenant-scoped, sans repli
    // =====================================================================

    public function test_an_organization_without_ai_settings_has_no_provider_even_if_the_platform_has_a_key(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('AI is not configured for Organization');

        app(ProviderResolver::class)->resolve(CapabilityRegistry::LOOP_SUMMARY, $this->context());
    }

    public function test_an_organization_without_credential_never_falls_back_to_the_platform_key(): void
    {
        OrganizationAiSetting::factory()->withoutCredential()->create(['organization_id' => $this->organization->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('has no credential configured for Organization');

        app(ProviderResolver::class)->resolve(CapabilityRegistry::LOOP_SUMMARY, $this->context());
    }

    public function test_a_disabled_configuration_has_no_provider(): void
    {
        OrganizationAiSetting::factory()->disabled()->create(['organization_id' => $this->organization->id]);

        $this->expectException(DomainException::class);

        app(ProviderResolver::class)->resolve(CapabilityRegistry::LOOP_SUMMARY, $this->context());
    }

    public function test_the_resolver_uses_the_organization_provider_model_and_credential(): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant-A',
        ]);

        $resolved = app(ProviderResolver::class)->resolve(CapabilityRegistry::LOOP_SUMMARY, $this->context());

        $this->assertSame('openrouter', $resolved->provider);
        $this->assertSame('openai/gpt-4o-mini', $resolved->model);
        $this->assertSame('org:'.$this->organization->id.':openrouter', $resolved->instance);
        // La trace ne porte ni instance ni secret.
        $this->assertSame('openrouter/openai/gpt-4o-mini', $resolved->trace());
        // L'instance SDK du tenant porte SA cle ; la cle plateforme est intacte.
        $this->assertSame('sk-or-tenant-A', config('ai.providers.'.$resolved->instance.'.key'));
        $this->assertSame('openrouter', config('ai.providers.'.$resolved->instance.'.driver'));
        $this->assertSame('platform-env-key', config('ai.providers.openrouter.key'));
    }

    public function test_two_organizations_get_distinct_instances_and_credentials(): void
    {
        $other = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'api_key' => 'sk-A']);
        OrganizationAiSetting::factory()->create(['organization_id' => $other->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-B']);

        $a = app(ProviderResolver::class)->resolve(CapabilityRegistry::LOOP_SUMMARY, $this->context());
        $b = app(ProviderResolver::class)->resolve(CapabilityRegistry::LOOP_SUMMARY, $this->context(organizationId: $other->id));

        $this->assertNotSame($a->instance, $b->instance);
        $this->assertSame('sk-A', config('ai.providers.'.$a->instance.'.key'));
        $this->assertSame('sk-B', config('ai.providers.'.$b->instance.'.key'));
        $this->assertSame('openai', $b->provider);
    }

    public function test_the_credential_is_encrypted_at_rest_and_never_serialized(): void
    {
        $setting = OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-or-very-secret',
        ]);

        $raw = DB::table('organization_ai_settings')->where('id', $setting->id)->value('api_key');

        $this->assertNotSame('sk-or-very-secret', $raw);
        $this->assertStringNotContainsString('very-secret', (string) $raw);
        $this->assertSame('sk-or-very-secret', $setting->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $setting->toArray());
        $this->assertStringNotContainsString('very-secret', $setting->toJson());
    }

    // =====================================================================
    // loop_summary et clarify_help_request : compatibles, degradation explicite
    // =====================================================================

    public function test_the_summary_is_prompted_on_the_tenant_instance(): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'model' => 'deepseek/deepseek-chat-v3-0324',
            'api_key' => 'sk-tenant',
        ]);
        $this->fakeSummary();

        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);

        LoopSummaryAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertSame('org:'.$this->organization->id.':openrouter', $prompt->provider->name());
            $this->assertSame('deepseek/deepseek-chat-v3-0324', $prompt->model);

            return true;
        });
        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('openrouter/deepseek/deepseek-chat-v3-0324', $interaction->model);
        $this->assertStringNotContainsString('sk-tenant', json_encode($interaction->toArray()));
    }

    public function test_the_summary_is_explicitly_unavailable_without_tenant_configuration(): void
    {
        $this->fakeSummary();

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('loops.ai_not_configured_for_organization'), $exception->getMessage());
        }

        LoopSummaryAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_the_clarification_falls_back_deterministically_without_tenant_configuration(): void
    {
        $this->fakeClarifier();

        $result = app(AiProvider::class)->clarifyForLoop($this->loop, $this->member, 'Je cherche une relecture de mon dossier européen.');

        $this->assertSame('deterministic_fallback', $result->producer);
        HelpRequestClarifierAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_the_clarification_is_prompted_on_the_tenant_instance(): void
    {
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'api_key' => 'sk-tenant']);
        $this->fakeClarifier();

        $result = app(AiProvider::class)->clarifyForLoop($this->loop, $this->member, 'Je cherche une relecture de mon dossier européen.');

        $this->assertSame('laravel_ai_sdk', $result->producer);
        HelpRequestClarifierAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertSame('org:'.$this->organization->id.':openrouter', $prompt->provider->name());

            return true;
        });
    }

    // =====================================================================
    // Budget Organization
    // =====================================================================

    public function test_the_organization_monthly_budget_refuses_before_any_call(): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'api_key' => 'sk-tenant',
            'monthly_budget_usd' => 0.50,
        ]);
        AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'chatloop.summarize',
            'feature' => 'chatloop_ai_summarize',
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'x',
            'response' => 'y',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => 0.50,
            'cost_unknown' => false,
            'metadata' => [],
        ]);
        // TASK-1260 : jumelle ledger — l'autorite generation du plafond
        // Organization depuis le cutover ; le reel ecrit les deux tables.
        AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'process' => 'chatloop.summarize',
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => 0.50,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
        $this->fakeSummary();
        $this->fakeClarifier();

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->member);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('loops.ai_summary_monthly_budget_reached'), $exception->getMessage());
        }
        LoopSummaryAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);

        $result = app(AiProvider::class)->clarifyForLoop($this->loop, $this->member, 'Je cherche une relecture de mon dossier européen.');
        $this->assertSame('deterministic_fallback', $result->producer);
        HelpRequestClarifierAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);

        $verdict = app(AiEconomicGuard::class)->authorize($this->organization->fresh(), 'chatloop.summarize', 'openrouter', 'openai/gpt-4o-mini', 100.0, 100);
        $this->assertFalse($verdict->allowed);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $verdict->reason);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function context(?string $organizationId = null): ContexteIa
    {
        return new ContexteIa(
            organizationId: $organizationId ?? $this->organization->id,
            userId: $this->member->id,
            loopId: $this->loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_SUMMARY,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
        );
    }

    private function fakeSummary(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Nous avons décidé de livrer vendredi.',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);
        LoopSummaryAgent::fake([
            new TextResponse('Synthèse.', new Usage(10, 20), new Meta('openrouter', 'deepseek/deepseek-chat-v3-0324')),
        ]);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Faire relire mon dossier européen',
            'clarified_request' => 'Je cherche une relecture structurée de mon dossier européen avant son dépôt.',
            'help_type' => 'review',
            'suggested_category_id' => '',
            'suggested_loop_id' => $this->loop->id,
            'suggestion_reason' => 'Cette Boucle réunit les membres concernés.',
            'questions_for_user' => [],
            'confidence' => 0.95,
            'needs_human_review' => false,
        ];
        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse($structured, json_encode($structured), new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }
}
