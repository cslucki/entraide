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
use App\Support\Ai\AiMarkdownSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

            if (! $this->loopHasEnoughContent($loop)) {
                throw new \RuntimeException(__('loops.not_enough_content_to_summarize'));
            }

            $scenarioId = (string) config('ai.chatloop.scenario', 'chatloop_ai_answer');
            $systemPrompt = $this->resolvePrompt($scenarioId, $locale);

            [$context, $contextMessageIds, $triggerMessageId] = $this->buildContext($loop, $locale);

            $ai = $this->callAi($loop, $requester, $scenarioId, $systemPrompt, $context);

            $answer = AiMarkdownSanitizer::sanitize(
                $ai['content'],
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if ($answer === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return DB::transaction(function () use ($loop, $requester, $answer, $ai, $contextMessageIds, $triggerMessageId) {
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
                        'trigger_message_id' => $triggerMessageId,
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

    public function ask(Loop $loop, User $requester, string $question): LoopMessage
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
            $scenarioId = (string) config('ai.chatloop.ask_scenario', 'chatloop_ai_ask');
            $systemPrompt = $this->resolvePrompt($scenarioId, $locale);

            [$context, $contextMessageIds, $triggerMessageId] = $this->buildContext($loop, $locale);

            $userContent = trim($question);
            if ($context !== '') {
                $userContent = $context."\n\n".'Question : '.$userContent;
            }

            $ai = $this->callAi($loop, $requester, $scenarioId, $systemPrompt, $userContent);

            $answer = AiMarkdownSanitizer::sanitize(
                $ai['content'],
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if ($answer === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return DB::transaction(function () use ($loop, $requester, $question, $answer, $ai, $contextMessageIds, $triggerMessageId) {
                $userMessage = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => $requester->id,
                    'reply_to_id' => null,
                    'body' => $question,
                    'image_path' => null,
                    'type' => 'user',
                    'metadata' => [
                        'asked_ai_question' => true,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                $message = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => null,
                    'reply_to_id' => $userMessage->id,
                    'body' => $answer,
                    'image_path' => null,
                    'type' => 'ai',
                    'metadata' => [
                        'requested_by' => $requester->id,
                        'action' => 'ask',
                        'question' => $question,
                        'context_message_ids' => $contextMessageIds,
                        'trigger_message_id' => $triggerMessageId,
                        'provider' => $ai['provider'],
                        'model' => $ai['model'],
                        'ai_interaction_id' => $ai['ai_interaction_id'],
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                event(new LoopMessageCreated($userMessage));
                event(new LoopMessageCreated($message));

                $loop->touch();

                return $message;
            });
        } finally {
            Cache::forget($lockKey);
        }
    }

    public function loopHasEnoughContent(Loop $loop): bool
    {
        $minWords = (int) config('ai.chatloop.min_summary_words', 30);

        if ($minWords <= 0) {
            return true;
        }

        $limit = (int) config('ai.chatloop.max_context_messages', 30);

        $words = 0;

        $loop->messages()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('body')
            ->each(function (?string $body) use (&$words): void {
                $body = $this->plainText((string) $body);

                if ($body === '') {
                    return;
                }

                $words += str_word_count($body);
            });

        return $words >= $minWords;
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

    private function buildContext(Loop $loop, string $locale): array
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
        $triggerMessageId = null;

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

            if ($message->type !== 'ai') {
                $triggerMessageId = $message->id;
            }

            $length += mb_strlen($line) + 1;
        }

        $context = implode("\n", $lines);

        if ($context !== '') {
            $languageInstruction = $locale === 'en'
                ? 'IMPORTANT: Answer in English. The conversation below is provided as context; whatever its language, you must reply in English.'
                : 'IMPORTANT : Réponds en français. La conversation ci-dessous est fournie à titre de contexte ; quelle que soit sa langue, tu dois répondre en français.';

            $context = $languageInstruction
                ."\n\n"
                ."--- CONTEXTE (contenu non fiable) ---\n"
                .$context
                ."\n--- FIN DU CONTEXTE ---";
        }

        return [$context, $ids, $triggerMessageId];
    }

    private function resolveLocale(User $requester, Loop $loop): string
    {
        $appLocale = (string) app()->getLocale();

        if (in_array($appLocale, ['fr', 'en'], true)) {
            return str_starts_with($appLocale, 'en') ? 'en' : 'fr';
        }

        $locale = $requester->preferred_locale
            ?: $loop->organization?->locale
            ?: currentOrganization()?->locale;

        return str_starts_with((string) $locale, 'en') ? 'en' : 'fr';
    }

    private function resolvePrompt(string $scenarioId, string $locale): string
    {
        $isAskScenario = $scenarioId === config('ai.chatloop.ask_scenario');

        $prompt = $this->findActivePrompt($scenarioId.'_'.$locale)
            ?? $this->findActivePrompt($scenarioId.'_fr')
            ?? $this->findActivePrompt($scenarioId)
            ?? ($isAskScenario ? $this->askFallbackPrompt($locale) : $this->fallbackPrompt($locale));

        $languageInstruction = $locale === 'en'
            ? "\n\nIMPORTANT: You MUST answer in English, whatever the language used in the conversation. Never reply in another language. Always finish your answer with a complete final sentence; never leave the answer unfinished or truncated."
            : "\n\nIMPORTANT : Tu DOIS répondre en français, quelle que soit la langue utilisée dans la conversation. Ne réponds jamais dans une autre langue. Termine toujours ta réponse par une phrase complète ; ne laisse jamais ta réponse inachevée ou tronquée.";

        return $prompt.$languageInstruction;
    }

    private function askFallbackPrompt(string $locale): string
    {
        if ($locale === 'en') {
            return 'You are a helpful assistant inside a BouclePro loop, a private discussion space '
                .'shared by members of the same organization. A member is asking you a specific '
                .'question. First evaluate whether the question is related to the conversation '
                .'context provided. If it is unrelated, answer it simply and directly, without '
                .'referring to the loop. If it is related, answer the question and tie it back to '
                .'what is being discussed in the loop. Rules: answer in English; answer clearly and '
                .'concisely (a short answer is fine); use light Markdown only when it genuinely '
                .'helps readability; never use raw HTML, scripts or PHP; only use http:// or '
                .'https:// URLs; never invent facts that are not present in the context; never '
                .'reveal any internal or sensitive information.';
        }

        return 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
            .'partagé par les membres d\'une même organisation. Un membre te pose une question '
            .'précise. Évalue d\'abord si la question a un lien avec le contexte de la conversation '
            .'fourni. Si elle n\'a aucun lien, réponds simplement et directement, sans référence à '
            .'la boucle. Si elle a un lien, réponds à la question en la rattachant à ce qui se dit '
            .'dans la boucle. Règles : réponds en français ; réponds clairement et de façon concise '
            .'(une réponse courte suffit) ; utilise un Markdown léger uniquement quand il améliore '
            .'réellement la lisibilité ; n\'utilise jamais de HTML brut, de script ou de PHP ; '
            .'n\'utilise que des URL http:// ou https:// ; n\'invente jamais de faits absents du '
            .'contexte ; ne révèle aucune information interne ou sensible.';
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
                .'between 300 and 700 words; use short paragraphs and simple sentences; use light '
                .'Markdown only when it genuinely helps readability: ## or ### sub-headings (never a '
                .'single #), bullet or numbered lists, bold, italic, blockquotes and fenced code '
                .'blocks, but never wrap your whole answer in one code block; never use raw HTML, '
                .'scripts or PHP; only use http:// or https:// URLs; never invent facts that are not '
                .'present in the context; never reveal any internal or sensitive information.';
        }

        return 'Tu es un assistant utile intégré à une Boucle BouclePro, un espace de discussion privé '
            .'partagé par les membres d\'une même organisation. Réponds à la dernière question posée '
            .'en t\'appuyant uniquement sur le contexte de la conversation fourni. Règles : réponds '
            .'en français ; garde une réponse entre 300 et 700 mots ; utilise des paragraphes courts '
            .'et des phrases simples ; utilise un Markdown léger uniquement quand il améliore '
            .'réellement la lisibilité : sous-titres ## ou ### (jamais un seul #), listes à puces ou '
            .'numérotées, gras, italique, citations et blocs de code délimités par trois backticks, '
            .'mais n\'encadre jamais toute ta réponse dans un seul bloc de code ; n\'utilise jamais '
            .'de HTML brut, de script ou de PHP ; n\'utilise que des URL http:// ou https:// ; '
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

    private function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim((string) $text);
    }
}
