<?php

namespace Tests\Feature\Services\Ai;

use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AssistedInteractionLabResult;
use App\Services\Ai\FakeAIProvider;
use App\Services\Ai\SupervisionProviderResolver;
use Tests\TestCase;

class ClarifyUserHelpRequestServiceTest extends TestCase
{
    private ClarifyUserHelpRequestService $service;

    private SupervisionProviderResolver $resolver;

    private AiScenarioFactory $scenarioFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->createMock(SupervisionProviderResolver::class);
        $this->scenarioFactory = $this->createMock(AiScenarioFactory::class);

        $this->service = new ClarifyUserHelpRequestService(
            $this->resolver,
            $this->scenarioFactory,
            new FakeAIProvider,
        );
    }

    public function test_falls_back_to_fake_provider_when_disabled(): void
    {
        config(['ai.clarify.enabled' => false]);

        $result = $this->service->analyze('Je cherche des conseils pour trouver mes premiers clients');

        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
        $this->assertSame('help_request', $result->intent);
        $this->assertSame(0.84, $result->confidence);
        $this->assertSame('Trouver mes premiers clients', $result->title);
        $this->assertFalse($result->needsFallback());
    }

    public function test_falls_back_to_fake_provider_when_no_scenario(): void
    {
        config(['ai.clarify.enabled' => true]);

        $this->scenarioFactory->method('resolve')->with('clarify_help_request')->willReturn(null);

        $result = $this->service->analyze('Je cherche des conseils');

        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
    }

    public function test_falls_back_to_fake_provider_when_no_provider_available(): void
    {
        config(['ai.clarify.enabled' => true]);

        $scenario = $this->createMock(AiScenarioDefinition::class);
        $this->scenarioFactory->method('resolve')->with('clarify_help_request')->willReturn($scenario);
        $this->resolver->method('defaultProvider')->willReturn(null);

        $result = $this->service->analyze('cherche quelqu\'un pour m\'aider');

        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
    }

    public function test_maps_high_confidence_result_without_fallback(): void
    {
        config(['ai.clarify.enabled' => true]);
        $this->setUpScenarioAndProvider();

        $result = $this->service->analyze('Je cherche des conseils pour trouver mes premiers clients');

        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
        $this->assertSame('help_request', $result->intent);
        $this->assertSame(0.87, $result->confidence);
        $this->assertSame('Aide pour trouver mes premiers clients', $result->title);
        $this->assertSame('Je cherche des conseils pour développer ma clientèle initiale et établir une stratégie de prospection efficace.', $result->need);
        $this->assertSame('information, conseil', $result->expectedHelpType);
        $this->assertSame('Bonjour, je lance mon activité et je cherche des retours concrets...', $result->messageDraft);
        $this->assertFalse($result->needsFallback());
        $this->assertFalse($result->isBlocked());
        $this->assertTrue($result->isHighConfidence());
        $this->assertNotNull($result->suggestedLoop);
        $this->assertSame('Développement commercial', $result->suggestedLoop['label']);
        $this->assertSame('clarify_help_request', $result->scenario);
    }

    public function test_maps_low_confidence_with_fallback(): void
    {
        config(['ai.clarify.enabled' => true]);

        $provider = $this->createMock(SupervisionProvider::class);
        $provider->method('runScenario')->willReturn([
            'title' => 'Demande vague',
            'clarified_request' => 'La demande manque de précision pour être reformulée.',
            'help_type' => 'other',
            'suggested_category' => '',
            'suggested_loop' => '',
            'questions_for_user' => ['Sur quel aspect précis es-tu bloqué ?', 'Quel type d\'aide cherches-tu ?'],
            'publishable_draft' => null,
            'confidence' => 0.42,
            'needs_human_review' => false,
        ]);

        $this->setUpScenarioAndProvider($provider);

        $result = $this->service->analyze('Je suis bloqué');

        $this->assertSame(0.42, $result->confidence);
        $this->assertTrue($result->needsFallback());
        $this->assertTrue($result->isLowConfidence());
        $this->assertCount(2, $result->fallback['questions']);
        $this->assertSame('Des questions de clarification sont nécessaires pour préciser la demande.', $result->fallback['reason']);
        $this->assertNull($result->suggestedLoop);
    }

    public function test_maps_needs_human_review_with_fallback(): void
    {
        config(['ai.clarify.enabled' => true]);

        $provider = $this->createMock(SupervisionProvider::class);
        $provider->method('runScenario')->willReturn([
            'title' => 'Demande ambiguë',
            'clarified_request' => '',
            'help_type' => 'other',
            'suggested_category' => '',
            'suggested_loop' => '',
            'questions_for_user' => [],
            'publishable_draft' => '',
            'confidence' => 0.35,
            'needs_human_review' => true,
        ]);

        $this->setUpScenarioAndProvider($provider);

        $result = $this->service->analyze('a besoin de relecture humaine');

        $this->assertTrue($result->needsFallback());
        $this->assertTrue($result->safety['needs_human_review']);
        $this->assertSame('La demande nécessite une relecture humaine avant publication.', $result->fallback['reason']);
        $this->assertTrue($result->humanValidation['required']);
    }

    public function test_maps_with_defaults_when_fields_missing(): void
    {
        config(['ai.clarify.enabled' => true]);

        $scenario = $this->createMock(AiScenarioDefinition::class);
        $this->scenarioFactory->method('resolve')->with('clarify_help_request')->willReturn($scenario);
        $this->resolver->method('defaultProvider')->willReturn('openai');

        $provider = $this->createMock(SupervisionProvider::class);
        $provider->method('runScenario')->willReturn([]);

        $this->resolver->method('resolve')->with('openai')->willReturn($provider);

        $result = $this->service->analyze('test empty result');

        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
        $this->assertSame('help_request', $result->intent);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame('Nouvelle demande', $result->title);
        $this->assertSame('autre', $result->expectedHelpType);
        $this->assertTrue($result->needsFallback());
        $this->assertTrue($result->safety['needs_human_review']);
    }

    public function test_to_array_contains_expected_keys(): void
    {
        config(['ai.clarify.enabled' => true]);
        $this->setUpScenarioAndProvider();

        $result = $this->service->analyze('test');
        $array = $result->toArray();

        $expectedKeys = [
            'intent', 'confidence', 'title', 'need', 'context',
            'expected_help_type', 'deadline', 'suggested_loop', 'tone',
            'message_draft', 'fallback', 'human_validation', 'safety',
            '_scenario', '_scenario_label',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: $key");
        }
    }

    public function test_maps_help_type_service_offer(): void
    {
        $this->assertHelpTypeMapping('service_offer', 'proposition de service');
    }

    public function test_maps_help_type_collaboration(): void
    {
        $this->assertHelpTypeMapping('collaboration', 'collaboration');
    }

    public function test_maps_help_type_information(): void
    {
        $this->assertHelpTypeMapping('information', 'information, conseil');
    }

    public function test_maps_help_type_support(): void
    {
        $this->assertHelpTypeMapping('support', 'soutien, accompagnement');
    }

    public function test_maps_help_type_other(): void
    {
        $this->assertHelpTypeMapping('other', 'autre');
    }

    public function test_maps_help_type_unknown_falls_to_other(): void
    {
        $this->assertHelpTypeMapping('unknown', 'autre');
    }

    private function assertHelpTypeMapping(string $input, string $expected): void
    {
        config(['ai.clarify.enabled' => true]);

        $provider = $this->createMock(SupervisionProvider::class);
        $provider->method('runScenario')->willReturn([
            'title' => 'Test',
            'clarified_request' => 'test',
            'help_type' => $input,
            'suggested_category' => '',
            'suggested_loop' => '',
            'questions_for_user' => [],
            'publishable_draft' => 'test',
            'confidence' => 0.9,
            'needs_human_review' => false,
        ]);

        $this->setUpScenarioAndProvider($provider);

        $result = $this->service->analyze('test mapping');
        $this->assertSame($expected, $result->expectedHelpType);
    }

    public function test_uses_ai_config_default_provider_when_config_default_null(): void
    {
        config([
            'ai.clarify.enabled' => true,
            'ai.default_provider' => null,
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => true,
            'ai.openrouter.model' => 'mistralai/ministral-3b-2512',
        ]);

        $resolver = new SupervisionProviderResolver;
        $providerName = $resolver->defaultProvider();

        $this->assertSame('openrouter', $providerName);
    }

    public function test_uses_ai_config_default_model_when_config_default_null(): void
    {
        config([
            'ai.clarify.enabled' => true,
            'ai.default_provider' => null,
            'ai.default_model' => null,
            'ai.openrouter.enabled' => true,
            'ai.openrouter.model' => 'mistralai/ministral-3b-2512',
        ]);

        $scenario = $this->createMock(AiScenarioDefinition::class);
        $this->scenarioFactory->method('resolve')->with('clarify_help_request')->willReturn($scenario);
        $this->resolver->method('defaultProvider')->willReturn('openrouter');

        $capturedModel = null;
        $provider = $this->createMock(SupervisionProvider::class);
        $provider->method('runScenario')->willReturnCallback(
            function ($scenario, $content, $model = null) use (&$capturedModel) {
                $capturedModel = $model;

                return $this->createDefaultProviderMock()->runScenario($scenario, $content, $model);
            }
        );
        $this->resolver->method('resolve')->with('openrouter')->willReturn($provider);

        $result = $this->service->analyze('test model resolution');

        $this->assertSame('mistralai/ministral-3b-2512', $capturedModel);
        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
    }

    public function test_falls_back_to_fake_when_no_provider_and_no_model(): void
    {
        config([
            'ai.clarify.enabled' => true,
            'ai.default_provider' => null,
            'ai.default_model' => null,
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => false,
        ]);

        $scenario = $this->createMock(AiScenarioDefinition::class);
        $this->scenarioFactory->method('resolve')->with('clarify_help_request')->willReturn($scenario);

        $resolver = new SupervisionProviderResolver;
        $this->assertNull($resolver->defaultProvider());
    }

    private function setUpScenarioAndProvider(?SupervisionProvider $provider = null): void
    {
        $scenario = $this->createMock(AiScenarioDefinition::class);
        $this->scenarioFactory->method('resolve')->with('clarify_help_request')->willReturn($scenario);
        $this->resolver->method('defaultProvider')->willReturn('openai');

        $provider ??= $this->createDefaultProviderMock();

        $this->resolver->method('resolve')->with('openai')->willReturn($provider);
    }

    private function createDefaultProviderMock(): SupervisionProvider
    {
        $provider = $this->createMock(SupervisionProvider::class);
        $provider->method('runScenario')->willReturn([
            'title' => 'Aide pour trouver mes premiers clients',
            'clarified_request' => 'Je cherche des conseils pour développer ma clientèle initiale et établir une stratégie de prospection efficace.',
            'help_type' => 'information',
            'suggested_category' => 'business',
            'suggested_loop' => 'Développement commercial',
            'questions_for_user' => [],
            'publishable_draft' => 'Bonjour, je lance mon activité et je cherche des retours concrets...',
            'confidence' => 0.87,
            'needs_human_review' => false,
        ]);

        return $provider;
    }
}
