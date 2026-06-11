<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\Logging\AiBenchmarkLogger;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use App\Services\Ai\Providers\LoggingSupervisionProvider;
use App\Services\Ai\Providers\OllamaSupervisionProvider;
use App\Services\Ai\Providers\OpenRouterSupervisionProvider;

class SupervisionProviderResolver
{
    public function resolve(string $provider): SupervisionProvider
    {
        return match ($provider) {
            'ollama' => $this->wrapWithLogging(
                app(OllamaSupervisionProvider::class),
                'ollama',
            ),
            'openrouter' => $this->wrapWithLogging(
                app(OpenRouterSupervisionProvider::class),
                'openrouter',
            ),
            default => app(SupervisionProvider::class),
        };
    }

    private function wrapWithLogging(SupervisionProvider $inner, string $providerName): LoggingSupervisionProvider
    {
        return new LoggingSupervisionProvider(
            $inner,
            app(AiBenchmarkLogger::class),
            app(AdminAiInteractionPersistence::class),
            $providerName,
        );
    }

    public function defaultProvider(): ?string
    {
        if (config('ai.ollama.enabled')) {
            return 'ollama';
        }

        if (config('ai.openrouter.enabled')) {
            return 'openrouter';
        }

        if (config('ai.openai.supervision_enabled')) {
            return 'openai';
        }

        return null;
    }

    public function availableProviders(): array
    {
        $providers = [];

        if (config('ai.ollama.enabled')) {
            $providers['ollama'] = [
                'label' => 'Ollama (local)',
                'type' => 'local',
                'models' => [
                    config('ai.ollama.model', 'llama3.2') => config('ai.ollama.model', 'llama3.2'),
                ],
            ];
        }

        if (config('ai.openrouter.enabled')) {
            $providers['openrouter'] = [
                'label' => 'OpenRouter',
                'type' => 'cloud_proxy',
                'models' => [
                    config('ai.openrouter.model', 'deepseek/deepseek-chat-v3-0324') => config('ai.openrouter.model', 'deepseek/deepseek-chat-v3-0324'),
                ],
            ];
        }

        if (config('ai.openai.supervision_enabled')) {
            $providers['openai'] = [
                'label' => 'OpenAI',
                'type' => 'cloud',
                'models' => [
                    'gpt-4o-mini' => 'GPT-4o Mini',
                    'gpt-4o' => 'GPT-4o',
                    'gpt-4.1-mini' => 'GPT-4.1 Mini',
                    'gpt-4.1-nano' => 'GPT-4.1 Nano',
                    'o4-mini' => 'o4-mini',
                ],
            ];
        }

        return $providers;
    }

    public function supportedScenarios(string $provider): array
    {
        return ['supervision_content', 'clarify_help_request'];
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
