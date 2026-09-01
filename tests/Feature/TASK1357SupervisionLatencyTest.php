<?php

namespace Tests\Feature;

use App\Models\AdminAiInteraction;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use App\Services\Ai\Providers\LoggingSupervisionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1357 — `latency_ms` de la supervision IA.
 *
 * Un appel plus rapide qu'une milliseconde mesure une duree entiere de 0 :
 * c'est une mesure VALIDE, pas une mesure manquante. Le contrat tenu ici est
 * donc « entier >= 0 », et la valeur persistee est l'arrondi de la duree
 * observee — jamais sa troncature.
 */
class TASK1357SupervisionLatencyTest extends TestCase
{
    use RefreshDatabase;

    private string $benchmarkPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->benchmarkPath = storage_path('framework/testing/ai-benchmarks-t1357-'.bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->benchmarkPath)) {
            foreach (glob($this->benchmarkPath.'/*.jsonl') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->benchmarkPath);
        }

        parent::tearDown();
    }

    public function test_supervise_persists_an_integer_latency_that_may_be_zero(): void
    {
        $this->provider()->supervise('Contenu supervise instantanement.');

        $interaction = AdminAiInteraction::query()->where('scenario_id', 'supervision_content')->firstOrFail();

        $this->assertNotNull($interaction->latency_ms);
        $this->assertGreaterThanOrEqual(0, $interaction->latency_ms);
    }

    public function test_run_scenario_persists_an_integer_latency_that_may_be_zero(): void
    {
        $this->provider()->runScenario($this->scenario(), 'Contenu de scenario.');

        $interaction = AdminAiInteraction::query()->where('scenario_id', 'scenario_t1357')->firstOrFail();

        $this->assertNotNull($interaction->latency_ms);
        $this->assertGreaterThanOrEqual(0, $interaction->latency_ms);
    }

    /**
     * Le journal de benchmark reste alimente et porte, lui, la duree observee
     * a deux decimales : la colonne entiere n'est pas la seule trace.
     */
    public function test_benchmark_log_records_the_observed_duration(): void
    {
        $this->provider()->supervise('Contenu supervise instantanement.');

        $lines = file($this->benchmarkPath.'/supervision_content.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'Le provider journalise chaque appel.');

        $logged = json_decode((string) end($lines), true)['latency_ms'];

        $this->assertIsNumeric($logged);
        $this->assertGreaterThanOrEqual(0, $logged);
    }

    private function provider(): LoggingSupervisionProvider
    {
        return new LoggingSupervisionProvider(
            $this->instantaneousProvider(),
            new AiBenchmarkLogger($this->benchmarkPath),
            app(AdminAiInteractionPersistence::class),
            'openai',
        );
    }

    private function instantaneousProvider(): SupervisionProvider
    {
        return new class implements SupervisionProvider
        {
            public function supervise(string $content, ?string $model = null): AiSupervisionResult
            {
                return new AiSupervisionResult(
                    summary: 'resume',
                    riskLevel: 'low',
                    category: ['slug' => 'divers', 'label' => 'Divers'],
                    skills: [],
                    unmatchedTerms: [],
                    needsHumanCategoryReview: false,
                    categoryReviewReason: '',
                    recommendations: [],
                    moderationFlag: false,
                    notes: '',
                    inputTokens: 10,
                    outputTokens: 20,
                    model: 'gpt-4o-mini',
                    estimatedCostUsd: 0.000015,
                    latencyMs: 0,
                );
            }

            public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
            {
                return ['summary' => 'resume'];
            }
        };
    }

    private function scenario(): AiScenarioDefinition
    {
        return new class implements AiScenarioDefinition
        {
            public function id(): string
            {
                return 'scenario_t1357';
            }

            public function name(): string
            {
                return 'Scenario T1357';
            }

            public function description(): ?string
            {
                return null;
            }

            public function providerHint(): string
            {
                return 'openai';
            }

            public function systemPrompt(): string
            {
                return 'prompt';
            }

            public function jsonSchema(): array
            {
                return [];
            }
        };
    }
}
