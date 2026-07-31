<?php

namespace App\Services\ChatLoop;

use App\Events\LoopMessageCreated;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChatLoopAiService
{
    public function answer(Loop $loop, User $requester): LoopMessage
    {
        $this->assertCanRequest($loop, $requester);

        $lockKey = 'chatloop_ai_lock:'.$loop->id;

        $lockTtl = max(
            (int) config('ai.chatloop.lock_ttl', 90),
            (int) config('ai.chatloop.timeout', 30) + 30,
        );

        if (! Cache::add($lockKey, true, $lockTtl)) {
            throw new \RuntimeException(__('loops.ai_generation_in_progress'));
        }

        try {
            $locale = $this->resolveLocale($requester, $loop);
            $scenarioId = (string) config('ai.chatloop.scenario', 'chatloop_ai_answer');
            $systemPrompt = $this->resolvePrompt($scenarioId, $locale);

            [$context, $contextMessageIds] = $this->buildContext($loop);

            $ai = $this->callAi($loop, $requester, $scenarioId, $systemPrompt, $context);

            $answer = $this->cleanAiText(
                $ai['content'],
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if ($answer === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return DB::transaction(function () use ($loop, $requester, $answer, $ai, $contextMessageIds) {
                $message = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => null,
                    'reply_to_id' => null,
                    'body' => $answer,
                    'image_path' => null,
                    'type' => 'ai',
                    'metadata' => [
                        'requested_by' => $requester->id,
                        'action' => 'answer',
                        'context_message_ids' => $contextMessageIds,
                        'provider' => $ai['provider'],
                        'model' => $ai['model'],
                        'ai_interaction_id' => $ai['ai_interaction_id'],
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                event(new LoopMessageCreated($message));

                $loop->touch();

                return $message;
            });
        } finally {
            Cache::forget($lockKey);
        }
    }

    private function assertCanRequest(Loop $loop, User $requester): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $requester->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new \RuntimeException(__('loops.not_an_active_member'));
        }

        if ($loop->organization_id !== $requester->organization_id) {
            throw new \RuntimeException(__('loops.cross_organization'));
        }
    }

    private function buildContext(Loop $loop): array
    {
        $limit = (int) config('ai.chatloop.max_context_messages', 30);
        $charBudget = (int) config('ai.chatloop.max_context_chars', 12000);

        $messages = $loop->messages()
            ->with('sender')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $lines = [];
        $ids = [];
        $length = 0;

        foreach ($messages as $message) {
            $body = $this->plainText((string) $message->body);

            if ($body === '') {
                continue;
            }

            $senderName = $message->sender
                ? $message->sender->publicDisplayName()
                : ($message->type === 'ai' ? 'BouclePro' : __('loops.type_system'));

            $line = $senderName.' : '.$body;

            if ($length > 0 && $length + mb_strlen($line) + 1 > $charBudget) {
                break;
            }

            $lines[] = $line;
            $ids[] = $message->id;
            $length += mb_strlen($line) + 1;
        }

        return [implode("\n", $lines), $ids];
    }

    private function resolveLocale(User $requester, Loop $loop): string
    {
        $locale = $requester->preferred_locale
            ?: $loop->organization?->locale
            ?: currentOrganization()?->locale
            ?: app()->getLocale();

        return str_starts_with((string) $locale, 'en') ? 'en' : 'fr';
    }

    private function resolvePrompt(string $scenarioId, string $locale): string
    {
        $prompt = $this->findActivePrompt($scenarioId.'_'.$locale)
            ?? $this->findActivePrompt($scenarioId.'_fr')
            ?? $this->findActivePrompt($scenarioId);

        if ($prompt !== null) {
            return $prompt;
        }

        return $this->fallbackPrompt($locale);
    }

    private function findActivePrompt(string $scenarioId): ?string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        return $prompt?->prompt_text;
    }

    private function fallbackPrompt(string $locale): string
    {
        if ($locale === 'en') {
            return 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
                .'shared by members of the same organization. Answer the latest question based only '
                .'on the conversation context provided. Rules: answer in English; keep the answer '
                .'between 300 and 700 words; use short paragraphs and simple sentences; never reply '
                .'with HTML, Markdown or script; never invent facts that are not present in the '
                .'context; never reveal any internal or sensitive information.';
        }

        return 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
            .'partagé par les membres d\'une même organisation. Réponds à la dernière question posée '
            .'en t\'appuyant uniquement sur le contexte de la conversation fourni. Règles : réponds '
            .'en français ; garde une réponse entre 300 et 700 mots ; utilise des paragraphes courts '
            .'et des phrases simples ; ne réponds jamais avec du HTML, du Markdown ou du script ; '
            .'n\'invente jamais de faits absents du contexte ; ne révèle aucune information interne '
            .'ou sensible.';
    }

    private function callAi(Loop $loop, User $user, string $scenarioId, string $systemPrompt, string $context): array
    {
        $provider = AiConfig::get('default_provider') ?: config('ai.default_provider', 'openai');

        $model = AiConfig::get('default_model') ?? config('ai.default_model') ?? match ($provider) {
            'openrouter' => config('ai.openrouter.model'),
            'ollama' => config('ai.ollama.model'),
            default => config('ai.openai.model'),
        };

        $config = match ($provider) {
            'ollama' => config('ai.ollama'),
            'openrouter' => config('ai.openrouter'),
            default => config('ai.openai'),
        };

        $apiKey = (string) ($config['api_key'] ?? '');
        $baseUrl = (string) ($config['base_url'] ?? 'https://api.openai.com/v1');
        $timeout = (int) ($config['timeout'] ?? 30);

        $maxTokens = (int) config('ai.chatloop.max_tokens', 512);
        $temperature = (float) config('ai.chatloop.temperature', 0.7);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $context],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        $startedAt = (int) (microtime(true) * 1000);

        try {
            if ($provider === 'ollama') {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post(rtrim($baseUrl, '/').'/api/generate', [
                        'model' => $model,
                        'prompt' => $systemPrompt."\n\n".$context,
                        'stream' => false,
                        'temperature' => $temperature,
                        'options' => ['num_predict' => $maxTokens],
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException(__('loops.ai_error'));
                }

                $text = trim((string) ($response->json('response') ?? ''));
                $inputTokens = 0;
                $outputTokens = (int) ($response->json('eval_count') ?? 0);
                $costUsd = 0.0;
            } else {
                $http = Http::timeout($timeout)->acceptJson()->asJson();

                if ($provider === 'openrouter') {
                    $http->withHeaders([
                        'Authorization' => 'Bearer '.$apiKey,
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => config('app.name'),
                    ]);
                } else {
                    $http->withToken($apiKey);
                }

                if ($apiKey === '') {
                    throw new \RuntimeException(__('loops.ai_error'));
                }

                $response = $http->post(rtrim($baseUrl, '/').'/chat/completions', $payload);

                if (! $response->successful()) {
                    throw new \RuntimeException(__('loops.ai_error'));
                }

                $body = $response->json();
                $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
                $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
                $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);
                $inputPrice = (float) ($config['input_price_per_1m'] ?? 0);
                $outputPrice = (float) ($config['output_price_per_1m'] ?? 0);
                $costUsd = round(($inputTokens / 1_000_000) * $inputPrice + ($outputTokens / 1_000_000) * $outputPrice, 6);
            }
        } catch (ConnectionException) {
            throw new \RuntimeException(__('loops.ai_error'));
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        $interaction = AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => currentOrganization()?->id ?? $user->organization_id,
            'feature' => $scenarioId,
            'model' => $provider.'/'.$model,
            'prompt' => $context,
            'response' => $text,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $costUsd,
            'metadata' => [
                'loop_id' => $loop->id,
                'requested_by' => $user->id,
                'latency_ms' => $latencyMs,
                'provider' => $provider,
            ],
        ]);

        return [
            'content' => $text,
            'provider' => $provider,
            'model' => $model,
            'ai_interaction_id' => $interaction->id,
        ];
    }

    private function cleanAiText(string $text, int $limit = 1400): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\?php|<\%|<\?xml/i', '', $text) ?? $text;
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;
        $text = preg_replace('/```[a-z0-9_-]*\s*/i', '', $text) ?? $text;
        $text = str_replace('```', '', $text);
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}(?:-{3,}|_{3,}|\*{3,})\s*$/m', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}>\s?/m', '', $text) ?? $text;
        $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/__(.*?)__/s', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '$1', $text) ?? $text;
        $text = preg_replace('/^\s*[-*+]\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*\d+[.)]\s+/m', '', $text) ?? $text;
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $text) ?? $text;
        $text = str_replace(['**', '__', '*'], '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\h*\n\h*/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return Str::limit(trim((string) $text), $limit, '');
    }

    private function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim((string) $text);
    }
}
