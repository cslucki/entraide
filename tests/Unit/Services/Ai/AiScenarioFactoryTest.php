<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\DTO\AiScenarioResult;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Scenarios\SupervisionContentScenario;
use Tests\TestCase;

class AiScenarioFactoryTest extends TestCase
{
    public function test_factory_resolves_supervision_content_scenario(): void
    {
        $factory = app(AiScenarioFactory::class);

        $scenario = $factory->resolve('supervision_content');

        $this->assertInstanceOf(AiScenarioDefinition::class, $scenario);
        $this->assertInstanceOf(SupervisionContentScenario::class, $scenario);
        $this->assertSame('supervision_content', $scenario->id());
        $this->assertSame('Supervision de contenu', $scenario->name());
        $this->assertSame('openai', $scenario->providerHint());
        $this->assertNotNull($scenario->description());
    }

    public function test_factory_returns_null_for_unknown_scenario(): void
    {
        $factory = app(AiScenarioFactory::class);

        $this->assertNull($factory->resolve('unknown_scenario'));
    }

    public function test_factory_contains_registered_scenarios(): void
    {
        $factory = app(AiScenarioFactory::class);
        $all = $factory->all();

        $this->assertCount(3, $all);
        $this->assertArrayHasKey('supervision_content', $all);
        $this->assertArrayHasKey('clarify_help_request', $all);
        $this->assertArrayHasKey('bounded_member_presentation', $all);
    }

    public function test_scenario_result_wraps_supervision_result(): void
    {
        $supervisionResult = new AiSupervisionResult(
            summary: 'Test summary',
            riskLevel: 'low',
            category: ['slug' => 'test', 'label' => 'Test'],
            skills: [],
            unmatchedTerms: [],
            needsHumanCategoryReview: false,
            categoryReviewReason: '',
            recommendations: [],
            moderationFlag: false,
            notes: '',
            inputTokens: 100,
            outputTokens: 50,
            model: 'gpt-4o-mini',
            estimatedCostUsd: 0.0001,
            latencyMs: 120,
        );

        $scenario = new SupervisionContentScenario();
        $result = AiScenarioResult::fromSupervisionResult($supervisionResult, $scenario, 150.0);

        $this->assertSame('supervision_content', $result->scenarioId);
        $this->assertSame('Supervision de contenu', $result->scenarioMeta['name']);
        $this->assertSame('openai', $result->scenarioMeta['provider_hint']);
        $this->assertSame(150.0, $result->executionTimeMs);
        $this->assertSame(100, $result->promptTokensUsed);
        $this->assertSame(50, $result->completionTokensUsed);
        $this->assertSame('Test summary', $result->supervisionResult->summary);
    }

    public function test_scenario_result_to_array_merges_supervision_and_meta(): void
    {
        $supervisionResult = new AiSupervisionResult(
            summary: 'Test',
            riskLevel: 'low',
            category: ['slug' => 'test', 'label' => 'Test'],
            skills: [],
            unmatchedTerms: [],
            needsHumanCategoryReview: false,
            categoryReviewReason: '',
            recommendations: [],
            moderationFlag: false,
            notes: '',
            inputTokens: 10,
            outputTokens: 5,
            model: 'gpt-4o-mini',
            estimatedCostUsd: 0.00001,
            latencyMs: 50,
        );

        $scenario = new SupervisionContentScenario();
        $result = AiScenarioResult::fromSupervisionResult($supervisionResult, $scenario);
        $array = $result->toArray();

        $this->assertSame('supervision_content', $array['scenario_id']);
        $this->assertSame('Test', $array['summary']);
        $this->assertSame('low', $array['risk_level']);
        $this->assertArrayHasKey('scenario_meta', $array);
        $this->assertArrayHasKey('execution_time_ms', $array);
    }

    public function test_supervision_content_scenario_returns_system_prompt(): void
    {
        $scenario = new SupervisionContentScenario();
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('assistant de supervision', $prompt);
        $this->assertStringContainsString('Taxonomie officielle des catégories', $prompt);
    }

    public function test_supervision_content_scenario_returns_json_schema(): void
    {
        $scenario = new SupervisionContentScenario();
        $schema = $scenario->jsonSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('summary', $schema['properties']);
        $this->assertArrayHasKey('risk_level', $schema['properties']);
        $this->assertArrayHasKey('category', $schema['properties']);
        $this->assertArrayHasKey('skills', $schema['properties']);
    }

    public function test_scenario_definition_has_system_prompt_method(): void
    {
        $reflection = new \ReflectionClass(AiScenarioDefinition::class);

        $this->assertTrue(
            $reflection->hasMethod('systemPrompt'),
            'AiScenarioDefinition interface must require systemPrompt() method'
        );

        $method = $reflection->getMethod('systemPrompt');
        $this->assertSame('string', $method->getReturnType()?->getName());
    }

    public function test_scenario_definition_has_json_schema_method(): void
    {
        $reflection = new \ReflectionClass(AiScenarioDefinition::class);

        $this->assertTrue(
            $reflection->hasMethod('jsonSchema'),
            'AiScenarioDefinition interface must require jsonSchema() method'
        );

        $method = $reflection->getMethod('jsonSchema');
        $this->assertSame('array', $method->getReturnType()?->getName());
    }

    public function test_supervision_content_scenario_implements_full_contract(): void
    {
        $scenario = new SupervisionContentScenario();

        $this->assertInstanceOf(AiScenarioDefinition::class, $scenario);

        // Verify all interface methods are callable and return expected types
        $this->assertSame('supervision_content', $scenario->id());
        $this->assertSame('Supervision de contenu', $scenario->name());
        $this->assertNotNull($scenario->description());
        $this->assertSame('openai', $scenario->providerHint());

        $prompt = $scenario->systemPrompt();
        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);

        $schema = $scenario->jsonSchema();
        $this->assertIsArray($schema);
        $this->assertNotEmpty($schema);
    }
}
