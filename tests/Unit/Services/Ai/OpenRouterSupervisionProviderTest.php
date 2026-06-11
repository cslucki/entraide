<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\Providers\OpenRouterSupervisionProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterSupervisionProviderTest extends TestCase
{
    private const VALID_RESPONSE_JSON = [
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
    ];

    public function test_provider_implements_supervision_provider_interface(): void
    {
        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $this->assertInstanceOf(SupervisionProvider::class, $provider);
    }

    public function test_supervise_sends_correct_payload_to_openrouter(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode(self::VALID_RESPONSE_JSON)]],
                ],
                'usage' => [
                    'prompt_tokens' => 42,
                    'completion_tokens' => 150,
                ],
            ]),
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
            siteName: 'TestApp',
            siteUrl: 'https://test.local',
        );

        $result = $provider->supervise('Contenu à analyser.');

        Http::assertSent(function ($request) {
            $url = $request->url();

            if (! str_contains($url, 'chat/completions')) {
                return false;
            }

            $body = json_decode($request->body(), true);
            if ($body['model'] !== 'openai/gpt-4o-mini') {
                return false;
            }

            $headers = $request->headers();
            $auth = $headers['Authorization'][0] ?? '';
            if ($auth !== 'Bearer sk-test') {
                return false;
            }

            $referer = $headers['HTTP-Referer'][0] ?? '';
            $xTitle = $headers['X-Title'][0] ?? '';
            if ($referer !== 'https://test.local' || $xTitle !== 'TestApp') {
                return false;
            }

            if (($body['messages'][0]['role'] ?? '') !== 'system') {
                return false;
            }
            if (($body['messages'][1]['role'] ?? '') !== 'user') {
                return false;
            }
            if (($body['messages'][1]['content'] ?? '') !== 'Contenu à analyser.') {
                return false;
            }
            if (($body['response_format']['type'] ?? '') !== 'json_schema') {
                return false;
            }

            return true;
        });
    }

    public function test_supervise_parses_valid_json_response(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'summary' => 'Résumé test',
                        'risk_level' => 'medium',
                        'category' => ['slug' => 'design', 'label' => 'Design'],
                        'skills' => [
                            ['slug' => 'copywriting', 'label' => 'Copywriting'],
                        ],
                        'unmatched_terms' => ['béton', 'cirque'],
                        'needs_human_category_review' => true,
                        'category_review_reason' => 'Plusieurs catégories possibles',
                        'recommendations' => ['Vérifier le contenu', 'Contacter le membre'],
                        'moderation_flag' => false,
                        'notes' => 'Note admin',
                    ])]],
                ],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 200,
                ],
            ]),
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $result = $provider->supervise('Test content');

        $this->assertInstanceOf(AiSupervisionResult::class, $result);
        $this->assertSame('Résumé test', $result->summary);
        $this->assertSame('medium', $result->riskLevel);
        $this->assertSame('design', $result->category['slug']);
        $this->assertSame('Design', $result->category['label']);
        $this->assertCount(1, $result->skills);
        $this->assertSame('copywriting', $result->skills[0]['slug']);
        $this->assertSame(['béton', 'cirque'], $result->unmatchedTerms);
        $this->assertTrue($result->needsHumanCategoryReview);
        $this->assertSame('Plusieurs catégories possibles', $result->categoryReviewReason);
        $this->assertSame(['Vérifier le contenu', 'Contacter le membre'], $result->recommendations);
        $this->assertFalse($result->moderationFlag);
        $this->assertSame('Note admin', $result->notes);
        $this->assertSame(100, $result->inputTokens);
        $this->assertSame(200, $result->outputTokens);
        $this->assertSame('openai/gpt-4o-mini', $result->model);
        $this->assertGreaterThan(0, $result->estimatedCostUsd);
        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    public function test_supervise_throws_on_failed_response(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response('', 500),
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Réponse OpenRouter invalide (HTTP 500).');

        $provider->supervise('test');
    }

    public function test_supervise_retries_on_rate_limit(): void
    {
        $calls = 0;

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => function () use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    return Http::response('Rate limited', 429);
                }

                return Http::response([
                    'choices' => [
                        ['message' => ['content' => json_encode(self::VALID_RESPONSE_JSON)]],
                    ],
                    'usage' => [
                        'prompt_tokens' => 10,
                        'completion_tokens' => 20,
                    ],
                ]);
            },
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $result = $provider->supervise('test');

        $this->assertInstanceOf(AiSupervisionResult::class, $result);
        $this->assertSame('Test summary', $result->summary);
        $this->assertSame(2, $calls);
    }

    public function test_supervise_respects_model_override(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode(self::VALID_RESPONSE_JSON)]],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 20,
                ],
            ]),
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $result = $provider->supervise('test', 'anthropic/claude-3-haiku');

        $this->assertSame('anthropic/claude-3-haiku', $result->model);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return ($body['model'] ?? '') === 'anthropic/claude-3-haiku';
        });
    }

    public function test_supervise_throws_when_api_key_empty(): void
    {
        $provider = new OpenRouterSupervisionProvider(
            apiKey: '',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Clé API OpenRouter manquante.');

        $provider->supervise('test');
    }

    public function test_supervise_handles_connection_error(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $this->expectException(SupervisionException::class);

        $provider->supervise('test');
    }

    public function test_supervise_handles_invalid_json_response(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'pas du json valide']],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $provider = new OpenRouterSupervisionProvider(
            apiKey: 'sk-test',
            baseUrl: 'https://openrouter.ai/api/v1',
            model: 'openai/gpt-4o-mini',
        );

        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Sortie JSON non décodable');

        $provider->supervise('test');
    }
}
