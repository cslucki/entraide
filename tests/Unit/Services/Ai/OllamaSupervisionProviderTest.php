<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\Providers\OllamaSupervisionProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaSupervisionProviderTest extends TestCase
{
    public function test_ollama_provider_throws_when_base_url_empty(): void
    {
        $provider = new OllamaSupervisionProvider('', 'llama3.2', 30);

        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Ollama non configuré.');

        $provider->supervise('test');
    }

    public function test_ollama_provider_supervise_with_fake_http(): void
    {
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'model' => 'llama3.2',
                'created_at' => now()->toIso8601String(),
                'response' => json_encode([
                    'summary' => 'Test summary',
                    'risk_level' => 'low',
                    'category' => ['slug' => 'tech-digital', 'label' => 'Tech & Digital'],
                    'skills' => [],
                    'unmatched_terms' => [],
                    'needs_human_category_review' => false,
                    'category_review_reason' => '',
                    'recommendations' => [],
                    'moderation_flag' => false,
                    'notes' => '',
                ]),
                'done' => true,
                'eval_count' => 150,
            ]),
        ]);

        $provider = new OllamaSupervisionProvider('http://localhost:11434', 'llama3.2', 30);
        $result = $provider->supervise('Contenu à analyser.');

        $this->assertInstanceOf(AiSupervisionResult::class, $result);
        $this->assertSame('Test summary', $result->summary);
        $this->assertSame('low', $result->riskLevel);
        $this->assertSame('tech-digital', $result->category['slug']);
        $this->assertSame(150, $result->outputTokens);
        $this->assertSame(0, $result->inputTokens);
        $this->assertSame(0.0, $result->estimatedCostUsd);
        $this->assertSame('llama3.2', $result->model);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    public function test_ollama_provider_handles_connection_error(): void
    {
        Http::fake([
            'localhost:11434/api/generate' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $provider = new OllamaSupervisionProvider('http://localhost:11434', 'llama3.2', 30);

        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Connexion Ollama impossible.');

        $provider->supervise('test');
    }

    public function test_ollama_provider_handles_invalid_json_response(): void
    {
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'model' => 'llama3.2',
                'response' => 'pas du json valide',
                'done' => true,
            ]),
        ]);

        $provider = new OllamaSupervisionProvider('http://localhost:11434', 'llama3.2', 30);

        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Sortie JSON Ollama non décodable.');

        $provider->supervise('test');
    }

    public function test_ollama_provider_uses_custom_model(): void
    {
        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'model' => 'mistral',
                'response' => json_encode([
                    'summary' => 'Custom model test',
                    'risk_level' => 'low',
                    'category' => ['slug' => 'autre', 'label' => 'Autre'],
                    'skills' => [],
                    'unmatched_terms' => [],
                    'needs_human_category_review' => false,
                    'category_review_reason' => '',
                    'recommendations' => [],
                    'moderation_flag' => false,
                    'notes' => '',
                ]),
                'done' => true,
                'eval_count' => 100,
            ]),
        ]);

        $provider = new OllamaSupervisionProvider('http://localhost:11434', 'llama3.2', 30);
        $result = $provider->supervise('test', 'mistral');

        $this->assertSame('mistral', $result->model);
    }

    public function test_ollama_payload_contains_stream_false_and_format_json(): void
    {
        Http::fake([
            'localhost:11434/api/generate' => function ($request) {
                $body = json_decode($request->body(), true);

                $this->assertFalse($body['stream']);
                $this->assertSame('json', $body['format']);
                $this->assertSame('llama3.2', $body['model']);
                $this->assertArrayHasKey('prompt', $body);
                $this->assertArrayHasKey('options', $body);
                $this->assertSame(900, $body['options']['num_predict']);

                return Http::response([
                    'model' => 'llama3.2',
                    'response' => json_encode([
                        'summary' => 'Payload test',
                        'risk_level' => 'low',
                        'category' => ['slug' => 'autre', 'label' => 'Autre'],
                        'skills' => [],
                        'unmatched_terms' => [],
                        'needs_human_category_review' => false,
                        'category_review_reason' => '',
                        'recommendations' => [],
                        'moderation_flag' => false,
                        'notes' => '',
                    ]),
                    'done' => true,
                    'eval_count' => 50,
                ]);
            },
        ]);

        $provider = new OllamaSupervisionProvider('http://localhost:11434', 'llama3.2', 30);
        $provider->supervise('test');

        // Assertions are inside the fake closure
        $this->assertTrue(true);
    }
}
