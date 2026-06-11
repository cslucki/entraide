<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiScenarioFactory;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\SupervisionProviderResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class AdminAiSupervisionController extends Controller
{
    public function __construct(
        protected SupervisionProviderResolver $resolver,
    ) {}

    public function index(): View
    {
        $factory = app(AiScenarioFactory::class);
        $defaultProvider = $this->resolver->defaultProvider();
        $providers = $this->resolver->availableProviders();
        $defaultModel = $providers[$defaultProvider]['models']
            ? array_key_first($providers[$defaultProvider]['models'])
            : '';

        $scenarioCompat = [];
        foreach ($factory->all() as $id => $scenario) {
            $supportedBy = [];
            foreach (array_keys($providers) as $providerKey) {
                if ($this->resolver->scenarioSupportsProvider($id, $providerKey)) {
                    $supportedBy[] = $providerKey;
                }
            }
            $scenarioCompat[$id] = $supportedBy;
        }

        return view('admin.ai-supervision.index', [
            'providers' => $providers,
            'provider' => $defaultProvider,
            'model' => $defaultModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'scenarios' => $factory->all(),
            'scenario' => 'supervision_content',
            'scenarioCompat' => $scenarioCompat,
            'defaultProvider' => $defaultProvider,
        ]);
    }

    public function analyze(Request $request): View
    {
        if (! config('ai.supervision.enabled', true)) {
            abort(403, 'Centre de supervision IA désactivé.');
        }

        $providerNames = array_keys($this->resolver->availableProviders());

        $data = $request->validate([
            'content' => ['required', 'string', 'min:3', 'max:5000'],
            'provider' => ['nullable', 'string', 'in:' . implode(',', $providerNames)],
            'model' => ['nullable', 'string'],
            'scenario' => ['nullable', 'string', 'in:supervision_content,clarify_help_request'],
        ]);

        $selectedProvider = $data['provider'] ?? $this->resolver->defaultProvider();
        $selectedScenario = $data['scenario'] ?? 'clarify_help_request';

        $providers = $this->resolver->availableProviders();
        $selectedModel = $data['model']
            ?? ($providers[$selectedProvider]['models']
                ? array_key_first($providers[$selectedProvider]['models'])
                : '');

        $error = null;
        $result = null;

        try {
            if ($selectedScenario === 'clarify_help_request') {
                if ($selectedProvider !== 'openai') {
                    throw new SupervisionException(
                        'Le scénario « Clarification de demande d\'aide » nécessite OpenAI (Responses API avec json_schema strict). Les providers locaux et proxy ne supportent pas encore ce scénario.'
                    );
                }

                $result = $this->runClarifyHelpRequest($data['content'], $selectedModel);
            } else {
                $provider = $this->resolver->resolve($selectedProvider);
                $result = $provider->supervise($data['content'], $selectedModel);
            }
        } catch (SupervisionException $e) {
            $error = $e->getMessage();
        }

        $factory = app(AiScenarioFactory::class);

        $scenarioCompat = [];
        foreach ($factory->all() as $id => $scenario) {
            $supportedBy = [];
            foreach (array_keys($providers) as $providerKey) {
                if ($this->resolver->scenarioSupportsProvider($id, $providerKey)) {
                    $supportedBy[] = $providerKey;
                }
            }
            $scenarioCompat[$id] = $supportedBy;
        }

        return view('admin.ai-supervision.index', [
            'providers' => $providers,
            'provider' => $selectedProvider,
            'model' => $selectedModel,
            'enabled' => (bool) config('ai.supervision.enabled', true),
            'content' => $data['content'],
            'result' => $result,
            'supervisionError' => $error,
            'scenarios' => $factory->all(),
            'scenario' => $selectedScenario,
            'scenarioCompat' => $scenarioCompat,
            'defaultProvider' => $this->resolver->defaultProvider(),
        ]);
    }

    private function runClarifyHelpRequest(string $content, string $model): array
    {
        $scenarioDefinition = app(AiScenarioFactory::class)->resolve('clarify_help_request');

        if (! $scenarioDefinition) {
            throw new SupervisionException('Scénario « Clarification de demande d\'aide » non trouvé.');
        }

        $config = $this->resolver->providerConfig('openai');

        if (empty($config['api_key'])) {
            throw new SupervisionException('Clé API OpenAI manquante.');
        }

        $payload = [
            'model' => $model ?: $config['model'],
            'max_output_tokens' => $config['max_output_tokens'],
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => $scenarioDefinition->systemPrompt()],
                ['role' => 'user', 'content' => $content],
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

        $response = Http::withToken($config['api_key'])
            ->timeout($config['timeout'])
            ->acceptJson()
            ->asJson()
            ->post(rtrim($config['base_url'], '/') . '/responses', $payload);

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

        app(AiBenchmarkLogger::class)->log([
            'timestamp' => now()->toIso8601String(),
            'scenario_id' => 'clarify_help_request',
            'provider' => 'openai',
            'model' => $model ?: $config['model'],
            'input_tokens' => $body['usage']['input_tokens'] ?? 0,
            'output_tokens' => $body['usage']['output_tokens'] ?? 0,
            'latency_ms' => $latencyMs,
            'cost_usd' => 0,
            'content_length' => mb_strlen($content),
            'status' => 'success',
        ]);

        return $parsed;
    }
}
