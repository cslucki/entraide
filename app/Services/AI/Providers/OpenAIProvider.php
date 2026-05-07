<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AISettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIProvider implements AIProviderInterface
{
    protected string $model;
    protected ?string $apiKey;

    public function __construct(AISettingsService $settings)
    {
        $this->model = $settings->getOpenAIModel();
        $this->apiKey = config('services.openai.key');
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if (!$this->apiKey) {
            return ['error' => 'Missing OpenAI API Key'];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0,
                ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                return json_decode($content, true) ?? ['error' => 'Invalid JSON from AI'];
            }

            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return ['error' => 'OpenAI API error: ' . $response->status()];
        } catch (\Exception $e) {
            Log::error('OpenAIProvider exception', [
                'message' => $e->getMessage()
            ]);
            return ['error' => 'Exception: ' . $e->getMessage()];
        }
    }
}
