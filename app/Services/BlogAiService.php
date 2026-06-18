<?php

namespace App\Services;

use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BlogAiService
{
    private const MAX_OUTPUT_TOKENS = 2048;

    private const TIMEOUT = 30;

    public function generate(BlogPost $post, User $user, ?string $title = null, ?string $summary = null): string
    {
        $title ??= $post->title;
        $summary ??= $post->summary;
        $prompt = "Rédige un article de blog structuré en HTML qui correspond au titre et au résumé suivants. Utilise des balises HTML valides (h2, h3, p, ul, li, etc.).\n\nTitre : {$title}\nRésumé : {$summary}";

        return $this->callAi($post, $user, $prompt, 'blog_generate');
    }

    public function correct(BlogPost $post, User $user): string
    {
        $prompt = "Corrige les fautes d'orthographe, de grammaire et de syntaxe dans le texte suivant. Ne modifie pas le contenu ni le style, corrige uniquement les erreurs.\n\n{$post->content}";

        return $this->callAi($post, $user, $prompt, 'blog_correct');
    }

    private function callAi(BlogPost $post, User $user, string $prompt, string $feature): string
    {
        $provider = config('ai.default_provider', AiConfig::get('default_provider', 'openai'));
        $model = config('ai.default_model', AiConfig::get('default_model', 'gpt-4o-mini'));

        $config = match ($provider) {
            'ollama' => config('ai.ollama'),
            'openrouter' => config('ai.openrouter'),
            default => config('ai.openai'),
        };

        if ($provider === 'ollama') {
            $model = config('ai.ollama.model', 'ministral-3:3b');
        }

        $apiKey = $config['api_key'] ?? '';
        $baseUrl = $config['base_url'] ?? 'https://api.openai.com/v1';
        $timeout = (int) ($config['timeout'] ?? self::TIMEOUT);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Tu es un assistant spécialisé dans la rédaction et la correction d\'articles de blog en français.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'temperature' => 0.7,
        ];

        $startedAt = (int) (microtime(true) * 1000);

        try {
            if ($provider === 'ollama') {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post(rtrim($baseUrl, '/').'/api/generate', [
                        'model' => $model,
                        'prompt' => "Tu es un assistant spécialisé dans la rédaction et la correction d'articles de blog en français.\n\n{$prompt}",
                        'stream' => false,
                        'temperature' => 0.7,
                        'options' => ['num_predict' => self::MAX_OUTPUT_TOKENS],
                    ]);

                if (! $response->successful()) {
                    $ollamaError = $response->json('error') ?? "Erreur IA (HTTP {$response->status()})";
                    throw new \RuntimeException((string) $ollamaError);
                }

                $text = trim((string) ($response->json('response') ?? $response->json('thinking') ?? ''));
                $inputTokens = 0;
                $outputTokens = (int) ($response->json('eval_count') ?? 0);
                $costUsd = 0;
            } else {
                $http = Http::timeout($timeout)->acceptJson()->asJson();

                if ($provider === 'openrouter') {
                    $http = $http->withHeaders([
                        'Authorization' => 'Bearer '.$apiKey,
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => config('app.name'),
                    ]);
                } else {
                    $http = $http->withToken($apiKey);
                }

                if (empty($apiKey)) {
                    throw new \RuntimeException('Clé API manquante pour le provider '.$provider.'.');
                }

                $response = $http->post(rtrim($baseUrl, '/').'/chat/completions', $payload);

                if (! $response->successful()) {
                    $apiError = $response->json('error') ?? $response->json('error')['message'] ?? "Erreur IA (HTTP {$response->status()})";
                    $errorMessage = is_string($apiError) ? $apiError : (is_array($apiError) ? ($apiError['message'] ?? "Erreur IA (HTTP {$response->status()})") : "Erreur IA (HTTP {$response->status()})");
                    throw new \RuntimeException($errorMessage);
                }

                $body = $response->json();
                $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
                $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
                $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);
                $inputPrice = (float) ($config['input_price_per_1m'] ?? 0);
                $outputPrice = (float) ($config['output_price_per_1m'] ?? 0);
                $costUsd = round(
                    ($inputTokens / 1_000_000) * $inputPrice
                    + ($outputTokens / 1_000_000) * $outputPrice,
                    6
                );
            }
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Connexion au service IA impossible.');
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        $organizationId = currentOrganization()?->id ?? $user->organization_id;

        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'feature' => $feature,
            'model' => $provider.'/'.$model,
            'prompt' => $prompt,
            'response' => $text,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $costUsd,
            'metadata' => [
                'blog_post_id' => $post->id,
                'latency_ms' => $latencyMs,
                'provider' => $provider,
            ],
        ]);

        return $text;
    }

    public function remainingCount(BlogPost $post, User $user, string $feature): int
    {
        if ($user->is_admin) {
            return PHP_INT_MAX;
        }

        $used = AiInteraction::where('user_id', $user->id)
            ->where('feature', $feature)
            ->where('metadata->blog_post_id', $post->id)
            ->count();

        return max(0, 3 - $used);
    }
}
