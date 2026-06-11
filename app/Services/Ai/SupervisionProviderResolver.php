<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Providers\OllamaSupervisionProvider;
use App\Services\Ai\Providers\OpenRouterSupervisionProvider;

class SupervisionProviderResolver
{
    public function resolve(string $provider): SupervisionProvider
    {
        return match ($provider) {
            'ollama' => app(OllamaSupervisionProvider::class),
            'openrouter' => app(OpenRouterSupervisionProvider::class),
            default => app(SupervisionProvider::class),
        };
    }

    public function defaultProvider(): string
    {
        if (config('ai.ollama.enabled')) {
            return 'ollama';
        }

        if (config('ai.openrouter.enabled')) {
            return 'openrouter';
        }

        return 'openai';
    }

    public function availableProviders(): array
    {
        $providers = [];

        if (config('ai.ollama.enabled')) {
            $providers['ollama'] = [
                'label' => 'Ollama (local)',
                'models' => [
                    config('ai.ollama.model', 'llama3.2') => config('ai.ollama.model', 'llama3.2'),
                ],
            ];
        }

        if (config('ai.openrouter.enabled')) {
            $providers['openrouter'] = [
                'label' => 'OpenRouter',
                'models' => [
                    config('ai.openrouter.model', 'openai/gpt-4o-mini') => config('ai.openrouter.model', 'openai/gpt-4o-mini'),
                ],
            ];
        }

        $providers['openai'] = [
            'label' => 'OpenAI',
            'models' => [
                'gpt-4o-mini' => 'GPT-4o Mini',
                'gpt-4o' => 'GPT-4o',
                'gpt-4.1-mini' => 'GPT-4.1 Mini',
                'gpt-4.1-nano' => 'GPT-4.1 Nano',
                'o4-mini' => 'o4-mini',
            ],
        ];

        return $providers;
    }

    public function supportedScenarios(string $provider): array
    {
        return match ($provider) {
            'openai' => ['supervision_content', 'clarify_help_request'],
            default => ['supervision_content'],
        };
    }

    public function scenarioSupportsProvider(string $scenario, string $provider): bool
    {
        return in_array($scenario, $this->supportedScenarios($provider), true);
    }

    public function providerConfig(string $provider): array
    {
        return match ($provider) {
            'ollama' => [
                'base_url' => config('ai.ollama.base_url', 'http://localhost:11434'),
                'api_key' => null,
                'model' => config('ai.ollama.model', 'llama3.2'),
                'timeout' => config('ai.ollama.timeout', 30),
                'max_output_tokens' => 900,
            ],
            'openrouter' => [
                'base_url' => config('ai.openrouter.base_url', 'https://openrouter.ai/api/v1'),
                'api_key' => config('ai.openrouter.api_key'),
                'model' => config('ai.openrouter.model', 'openai/gpt-4o-mini'),
                'timeout' => config('ai.openrouter.timeout', 30),
                'max_output_tokens' => config('ai.openrouter.max_output_tokens', 900),
            ],
            default => [
                'base_url' => config('ai.openai.base_url', 'https://api.openai.com/v1'),
                'api_key' => config('ai.openai.api_key'),
                'model' => config('ai.openai.model', 'gpt-4o-mini'),
                'timeout' => config('ai.openai.timeout', 15),
                'max_output_tokens' => config('ai.openai.max_output_tokens', 900),
            ],
        };
    }
}
