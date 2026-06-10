<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class AdminAiSupervisionController extends Controller
{
    private const AVAILABLE_MODELS = [
        'gpt-4o-mini' => 'GPT-4o Mini (rapide, économique)',
        'gpt-4o' => 'GPT-4o (précis, plus coûteux)',
        'gpt-4.1-mini' => 'GPT-4.1 Mini',
        'gpt-4.1-nano' => 'GPT-4.1 Nano',
        'o4-mini' => 'o4-mini (raisonnement)',
    ];

    public function __construct(
        protected SupervisionProvider $provider,
    ) {}

    public function index(): View
    {
        $factory = app(AiScenarioFactory::class);
        return view('admin.ai-supervision.index', [
            'models' => self::AVAILABLE_MODELS,
            'model' => (string) config('ai.openai.model'),
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'scenarios' => $factory->all(),
            'scenario' => 'supervision_content',
        ]);
    }

    public function analyze(Request $request): View
    {
        if (! config('ai.supervision.enabled', true)) {
            abort(403, 'Centre de supervision IA désactivé.');
        }

        $data = $request->validate([
            'content' => ['required', 'string', 'min:3', 'max:5000'],
            'model' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::AVAILABLE_MODELS))],
            'scenario' => ['nullable', 'string', 'in:supervision_content,clarify_help_request'],
        ]);

        $selectedModel = $data['model'] ?? (string) config('ai.openai.model');
        $selectedScenario = $data['scenario'] ?? 'supervision_content';

        $error = null;
        $result = null;

        try {
            if ($selectedScenario === 'clarify_help_request') {
                $scenarioDefinition = app(AiScenarioFactory::class)->resolve('clarify_help_request');
                if (! $scenarioDefinition) {
                    $error = 'Scénario clarify_help_request non trouvé.';
                } else {
                    $apiKey = (string) config('ai.openai.api_key');
                    $baseUrl = (string) config('ai.openai.base_url', 'https://api.openai.com/v1');
                    $maxTokens = (int) config('ai.openai.max_output_tokens', 900);
                    $timeout = (int) config('ai.openai.timeout', 15);

                    if ($apiKey === '') {
                        throw new SupervisionException('Clé API OpenAI manquante.');
                    }

                    $payload = [
                        'model' => $selectedModel,
                        'max_output_tokens' => $maxTokens,
                        'store' => false,
                        'input' => [
                            ['role' => 'system', 'content' => $scenarioDefinition->systemPrompt()],
                            ['role' => 'user', 'content' => $data['content']],
                        ],
                        'text' => [
                            'format' => [
                                'type' => 'json_schema',
                                'name' => 'clarify_help_request',
                                'strict' => true,
                                'schema' => $scenarioDefinition->jsonSchema(),
                            ],
                        ],
                    ];

                    $startedAt = microtime(true) * 1000;

                    $response = Http::withToken($apiKey)
                        ->timeout($timeout)
                        ->acceptJson()
                        ->asJson()
                        ->post(rtrim($baseUrl, '/') . '/responses', $payload);

                    if ($response->failed()) {
                        throw new SupervisionException(
                            'Réponse OpenAI invalide (HTTP ' . $response->status() . ').'
                        );
                    }

                    $body = $response->json();

                    $text = $body['output_text'] ?? '';
                    if ($text === '') {
                        $output = $body['output'] ?? [];
                        foreach ($output as $item) {
                            $contents = $item['content'] ?? [];
                            foreach ($contents as $piece) {
                                if (($piece['type'] ?? null) === 'output_text' && isset($piece['text'])) {
                                    $text = (string) $piece['text'];
                                    break 2;
                                }
                            }
                        }
                    }

                    $parsed = json_decode($text, true);
                    if (! is_array($parsed)) {
                        throw new SupervisionException('Sortie JSON OpenAI non décodable.');
                    }

                    $latencyMs = round(microtime(true) * 1000 - $startedAt, 2);

                    $inputTokens = $body['usage']['input_tokens'] ?? 0;
                    $outputTokens = $body['usage']['output_tokens'] ?? 0;

                    app(AiBenchmarkLogger::class)->log([
                        'timestamp' => now()->toIso8601String(),
                        'scenario_id' => 'clarify_help_request',
                        'model' => $selectedModel,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'latency_ms' => $latencyMs,
                        'cost_usd' => 0,
                        'content_length' => mb_strlen($data['content']),
                        'status' => 'success',
                    ]);

                    $result = $parsed;
                }
            } else {
                $result = $this->provider->supervise($data['content'], $selectedModel);
            }
        } catch (SupervisionException $e) {
            $error = $e->getMessage();
        }

        $factory = app(AiScenarioFactory::class);

        return view('admin.ai-supervision.index', [
            'models' => self::AVAILABLE_MODELS,
            'model' => $selectedModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'content' => $data['content'],
            'result' => $result,
            'supervisionError' => $error,
            'scenarios' => $factory->all(),
            'scenario' => $selectedScenario,
        ]);
    }
}
