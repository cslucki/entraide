<?php

namespace App\Services;

use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\BlogAiConfig;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BlogAiService
{
    private const MAX_OUTPUT_TOKENS = 2048;

    private const TIMEOUT = 30;

    private const METHOD_SELECTION_METHODS = ['explorer', 'clarifier', 'slow_down', 'invent'];

    private const GENERATED_ARTICLE_START_TAGS = ['<article', '<section', '<div', '<h1', '<h2', '<h3', '<h4', '<p', '<ul', '<ol', '<blockquote'];

    private const GENERATED_ARTICLE_CLOSING_TAGS = ['</article>', '</section>', '</div>', '</h1>', '</h2>', '</h3>', '</h4>', '</p>', '</ul>', '</ol>', '</blockquote>'];

    public function generate(BlogPost $post, User $user, ?string $title = null, ?string $summary = null): array
    {
        $title ??= $post->title;
        $summary ??= $post->summary;

        $promptText = $this->resolvePrompt('blog_generate');
        $prompt = sprintf($promptText, $title, $summary);
        $prompt .= $this->articleGenerationLanguageInstruction();

        $result = $this->callAi($post, $user, $prompt, 'blog_generate');

        return $this->buildResult($result, $user, 'blog_generate', $title, $summary);
    }

    public function correct(BlogPost $post, User $user): array
    {
        $promptText = $this->resolvePrompt('blog_correct');
        $prompt = sprintf($promptText, $post->content);

        $result = $this->callAi($post, $user, $prompt, 'blog_correct');

        return $this->buildResult($result, $user, 'blog_correct');
    }

    public function methodSelection(
        BlogPost $post,
        User $user,
        string $method,
        string $selectedText,
        ?string $contextBefore = null,
        ?string $contextAfter = null
    ): array {
        if (! in_array($method, self::METHOD_SELECTION_METHODS, true)) {
            throw new \InvalidArgumentException('Invalid method.');
        }

        $locale = $this->resolveMethodLocale($post, $user);
        $scenarioId = "blog_method_selection_{$method}_{$locale}";
        $methodName = trans("blog.method_{$method}", [], $locale);
        $promptText = $this->resolvePrompt($scenarioId);

        $prompt = sprintf(
            $promptText,
            $methodName,
            $this->plainText($post->title),
            $this->plainText($selectedText),
            $this->plainText($contextBefore ?: __('blog.method_selection_no_context', [], $locale)),
            $this->plainText($contextAfter ?: __('blog.method_selection_no_context', [], $locale)),
        );

        $prompt .= $locale === 'en'
            ? "\n\nReturn a single short editable suggestion. Plain text only, no Markdown, no HTML, no bullets."
            : "\n\nRetourne une seule suggestion courte et éditable. Texte brut uniquement, sans Markdown, sans HTML, sans liste.";

        $result = $this->callAi($post, $user, $prompt, $scenarioId);

        $cleaned = $this->cleanAiText($result['content']);

        return [
            'content' => $this->truncateToSentenceBoundary($cleaned, 650),
            'provider' => $result['provider'],
            'model' => $result['model'],
            'method' => $method,
            'method_name' => $methodName,
            'scope' => 'selection',
            'ai_interaction_id' => $result['ai_interaction_id'] ?? null,
        ];
    }

    public function remainingCount(BlogPost $post, User $user, string $feature): int
    {
        $orgId = currentOrganization()?->id ?? $user->organization_id;
        $config = BlogAiConfig::forOrganization($orgId);

        $limit = $feature === 'blog_generate' ? $config->generate_limit : $config->correct_limit;

        $used = AiInteraction::where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->where('feature', $feature)
            ->where('metadata->blog_post_id', $post->id)
            ->count();

        return max(0, $limit - $used);
    }

    public function checkEnabled(string $feature, User $user): array
    {
        $orgId = currentOrganization()?->id ?? $user->organization_id;
        $config = BlogAiConfig::forOrganization($orgId);

        $key = $feature === 'blog_generate' ? 'generate_enabled' : 'correct_enabled';

        return [
            'enabled' => $config->$key,
            'limit' => $feature === 'blog_generate' ? $config->generate_limit : $config->correct_limit,
        ];
    }

    public function getProviderInfo(): array
    {
        $provider = AiConfig::get('default_provider') ?: config('ai.default_provider', 'openai');
        $model = AiConfig::get('default_model')
            ?? config('ai.default_model')
            ?? match ($provider) {
                'openrouter' => config('ai.openrouter.model'),
                'ollama' => config('ai.ollama.model'),
                default => config('ai.openai.model'),
            };

        return compact('provider', 'model');
    }

    private function resolvePrompt(string $feature): string
    {
        $prompt = AdminAiPrompt::where('scenario_id', $feature)
            ->where('is_active', true)
            ->orderBy('version', 'desc')
            ->first();

        if ($prompt) {
            return $prompt->prompt_text;
        }

        return match ($feature) {
            'blog_generate' => "Rédige un article de blog structuré en HTML qui correspond au titre et au résumé suivants. Utilise des balises HTML valides (h2, h3, p, ul, li, etc.). Ta réponse doit faire 500 mots maximum. Réponds UNIQUEMENT avec le contenu HTML, sans introduction, sans conclusion. NE reproduis PAS le titre (pas de h1/h2 avec le titre) et NE reproduis PAS le résumé (pas de premier paragraphe avec le résumé). Le titre et le résumé sont déjà gérés séparément.\n\nTitre : %s\nRésumé : %s",
            'blog_correct' => "Corrige les fautes d'orthographe, de grammaire et de syntaxe dans le texte suivant. Ne modifie pas le contenu ni le style, corrige uniquement les erreurs.\n\n%s",
            default => "Tu es un assistant éditorial. Analyse uniquement le passage sélectionné selon la méthode demandée. Retourne une réponse courte, humaine, en texte brut, sans HTML, sans Markdown, sans astérisques, sans titres Markdown, sans chat général. Utilise uniquement ces titres textuels simples : Observation, Question, Piste. Vise 300 à 500 caractères. Une seule piste principale.\n\nMéthode : %s\nTitre de l'article : %s\nPassage sélectionné : %s\nContexte avant : %s\nContexte après : %s",
        };
    }

    private function callAi(BlogPost $post, User $user, string $prompt, string $feature): array
    {
        $provider = AiConfig::get('default_provider') ?: config('ai.default_provider', 'openai');
        $model = AiConfig::get('default_model')
            ?? config('ai.default_model')
            ?? match ($provider) {
                'openrouter' => config('ai.openrouter.model'),
                'ollama' => config('ai.ollama.model'),
                default => config('ai.openai.model'),
            };

        $config = match ($provider) {
            'ollama' => config('ai.ollama'),
            'openrouter' => config('ai.openrouter'),
            default => config('ai.openai'),
        };

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

        $interaction = AiInteraction::create([
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

        return [
            'content' => $text,
            'provider' => $provider,
            'model' => $model,
            'ai_interaction_id' => $interaction->id,
        ];
    }

    private function resolveMethodLocale(BlogPost $post, User $user): string
    {
        $locale = $user->preferred_locale
            ?: $post->organization?->locale
            ?: currentOrganization()?->locale
            ?: app()->getLocale();

        return str_starts_with(strtolower((string) $locale), 'en') ? 'en' : 'fr';
    }

    private function articleGenerationLanguageInstruction(): string
    {
        return app()->getLocale() === 'en'
            ? "\n\nMandatory language: write the generated article in English. Do not switch to French."
            : "\n\nLangue obligatoire : rédige l'article généré en français. Ne bascule pas en anglais.";
    }

    private function cleanAiText(string $text, int $limit = 1400): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\?php|<\%|<\?xml/i', '', $text);
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text);
        $text = preg_replace('/```[a-z0-9_-]*\s*/i', '', $text);
        $text = str_replace('```', '', $text);
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text);
        $text = preg_replace('/^\s{0,3}(?:-{3,}|_{3,}|\*{3,})\s*$/m', '', $text);
        $text = preg_replace('/^\s{0,3}>\s?/m', '', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text);
        $text = preg_replace('/__(.*?)__/s', '$1', $text);
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $text);
        $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '$1', $text);
        $text = preg_replace('/^\s*[-*+]\s+/m', '', $text);
        $text = preg_replace('/^\s*\d+[.)]\s+/m', '', $text);
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $text);
        $text = str_replace(['**', '__', '*'], '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\h*\n\h*/', "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return Str::limit(trim((string) $text), $limit, '');
    }

    private function truncateToSentenceBoundary(string $text, int $limit): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $limit);

        $punctuations = ['.', '!', '?', '…'];
        $lastBoundary = -1;

        foreach ($punctuations as $p) {
            $pos = mb_strrpos($truncated, $p);
            if ($pos !== false && $pos > $lastBoundary) {
                $afterPunct = mb_substr($truncated, $pos + 1, 1);
                if ($afterPunct === '' || ctype_space($afterPunct) || $afterPunct === "\xC2\xA0") {
                    $lastBoundary = $pos;
                }
            }
        }

        if ($lastBoundary >= 0) {
            return trim(mb_substr($truncated, 0, $lastBoundary + 1));
        }

        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            return trim(mb_substr($truncated, 0, $lastSpace));
        }

        return trim($truncated);
    }

    private function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    private function buildResult(array $callResult, User $user, string $feature, ?string $title = null, ?string $summary = null): array
    {
        $orgId = currentOrganization()?->id ?? $user->organization_id;
        $config = BlogAiConfig::forOrganization($orgId);

        $limit = $feature === 'blog_generate' ? $config->generate_limit : $config->correct_limit;
        $content = $feature === 'blog_generate'
            ? $this->cleanGeneratedArticleHtml($callResult['content'], $title, $summary)
            : $callResult['content'];

        return [
            'content' => $content,
            'provider' => $callResult['provider'],
            'model' => $callResult['model'],
            'limit' => $limit,
        ];
    }

    private function cleanGeneratedArticleHtml(string $html, ?string $title = null, ?string $summary = null): string
    {
        $html = trim($html);

        if ($html === '') {
            return $html;
        }

        if (preg_match('/```(?:html)?\s*(.*?)```/is', $html, $matches)) {
            $html = $matches[1];
        }

        $html = preg_replace('/^\s*```[a-zA-Z0-9_-]*\s*/', '', (string) $html);
        $html = preg_replace('/\s*```\s*$/', '', (string) $html);
        $html = str_replace('```', '', (string) $html);
        $html = trim((string) $html);

        $firstTagPosition = null;
        foreach (self::GENERATED_ARTICLE_START_TAGS as $tag) {
            $position = stripos($html, $tag);
            if ($position !== false && ($firstTagPosition === null || $position < $firstTagPosition)) {
                $firstTagPosition = $position;
            }
        }

        if ($firstTagPosition !== null && $firstTagPosition > 0) {
            $html = substr($html, $firstTagPosition);
        }

        $lastClosingTag = null;
        $lastClosingTagLength = 0;
        foreach (self::GENERATED_ARTICLE_CLOSING_TAGS as $tag) {
            $position = strripos($html, $tag);
            if ($position !== false && ($lastClosingTag === null || $position > $lastClosingTag)) {
                $lastClosingTag = $position;
                $lastClosingTagLength = strlen($tag);
            }
        }

        if ($lastClosingTag !== null) {
            $html = substr($html, 0, $lastClosingTag + $lastClosingTagLength);
        }

        if ($title !== null || $summary !== null) {
            $html = $this->stripTitleSummaryFromHtml($html, $title, $summary);
        }

        return trim($html);
    }

    private function stripTitleSummaryFromHtml(string $html, ?string $title, ?string $summary): string
    {
        if (empty($title) && empty($summary)) {
            return $html;
        }

        if ($title !== null) {
            $trimmedTitle = trim($title);
            $escaped = preg_quote($trimmedTitle, '/');
            foreach (['h1', 'h2'] as $tag) {
                $html = preg_replace(
                    '/<'.$tag.'[^>]*>\s*'.$escaped.'\s*<\/'.$tag.'>\s*/iu',
                    '',
                    $html
                );
            }
        }

        if ($summary !== null) {
            $trimmedSummary = trim($summary);
            $escaped = preg_quote($trimmedSummary, '/');
            $html = preg_replace(
                '/<p[^>]*>\s*'.$escaped.'\s*<\/p>\s*/iu',
                '',
                $html,
                1
            );
        }

        $html = $this->normalizeHeadingLevels($html);

        return trim($html);
    }

    private function normalizeHeadingLevels(string $html): string
    {
        if (! preg_match_all('/<h(\d)/i', $html, $matches)) {
            return $html;
        }

        $levels = array_map('intval', $matches[1]);
        $minLevel = min($levels);

        if ($minLevel <= 2) {
            return $html;
        }

        $offset = 2 - $minLevel;

        $html = preg_replace_callback('/<h(\d)(\s|>)/i', function ($matches) use ($offset) {
            $newLevel = max(1, min(6, (int) $matches[1] + $offset));

            return '<h'.$newLevel.$matches[2];
        }, $html);

        $html = preg_replace_callback('/<\/h(\d)>/i', function ($matches) use ($offset) {
            $newLevel = max(1, min(6, (int) $matches[1] + $offset));

            return '</h'.$newLevel.'>';
        }, $html);

        return $html;
    }
}
