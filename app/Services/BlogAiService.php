<?php

namespace App\Services;

use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogAiConfig;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Chemin IA HERITE du Blog (generation, correction, methode sur selection).
 *
 * TASK-1247 : l'AUTORITE ECONOMIQUE est fermee — et seulement elle. Avant tout
 * appel provider : `AiEconomicGuard::authorize()` (budget mensuel de
 * l'Organization, budget/quota d'inconnus par process `blog.*`, credit IA du
 * demandeur — la meme autorite que les capabilities canoniques). Apres :
 * une ligne du ledger canonique `ai_provider_invocations` par appel
 * reellement tente (succes ET echec), avec le credential PROUVE `platform`
 * (la cle est lue ici, dans la configuration plateforme, et declaree telle
 * quelle — jamais deduite), et la trace produit `ai_interactions` sur succes
 * comme avant (l'autorite de lecture 1219/1228 la lit deja : double ecriture,
 * zero double comptage). Le tenant de la trace est l'Organization de
 * l'ARTICLE, jamais celle de la requete.
 *
 * Ce que TASK-1247 ne fait PAS (hors V1, BLOC E) : ni CapabilityRegistry, ni
 * Constitution/doctrine, ni ContextBuilder, ni credential BYOK Organization.
 * Le quota produit par article (`BlogAiConfig`) reste une regle produit,
 * distincte de l'autorite economique.
 */
class BlogAiService
{
    public function __construct(
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

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

        $parsed = $this->parseGenerateResponse($result['content'], $title, $summary);

        return $this->buildResult($result, $user, 'blog_generate', $parsed['title'], $parsed['summary'], $parsed['content']);
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
            'blog_generate' => "Rédige un article de blog en te basant sur le titre et le résumé fournis. Tu dois retourner un objet JSON unique avec exactement ces 3 champs :\n- \"title\" : le titre amélioré de l'article (string)\n- \"summary\" : un résumé percutant de 1 à 2 phrases (string)\n- \"content\" : le corps de l'article en HTML structuré avec des balises h2, h3, p, ul, li (string). Maximum 500 mots. Pas de balise h1 ni de h2 avec le titre.\n\nRetourne UNIQUEMENT le JSON brut, sans markdown, sans introduction, sans texte avant ou après.\n\nTitre fourni : %s\nRésumé fourni : %s",
            'blog_correct' => "Corrige les fautes d'orthographe, de grammaire et de syntaxe dans le texte suivant. Ne modifie pas le contenu ni le style, corrige uniquement les erreurs.\n\n%s",
            default => "Tu es un assistant éditorial. Analyse uniquement le passage sélectionné selon la méthode demandée. Retourne une réponse courte, humaine, en texte brut, sans HTML, sans Markdown, sans astérisques, sans titres Markdown, sans chat général. Utilise uniquement ces titres textuels simples : Observation, Question, Piste. Vise 300 à 500 caractères. Une seule piste principale.\n\nMéthode : %s\nTitre de l'article : %s\nPassage sélectionné : %s\nContexte avant : %s\nContexte après : %s",
        };
    }

    /**
     * Un appel provider, sous l'autorite economique (TASK-1247).
     *
     * Ordre garanti : tenant -> provider/modele/credential plateforme ->
     * GARDE (aucun appel si refus, rien d'ecrit) -> appel HTTP -> ledger
     * (succes ou echec) -> trace produit (succes seulement).
     *
     * @return array{content: string, provider: string, model: string, ai_interaction_id: string}
     *
     * @throws AiRefusedException refus economique AVANT l'appel (code stable)
     * @throws \RuntimeException echec provider (deja tente, ledger ecrit)
     */
    private function callAi(BlogPost $post, User $user, string $prompt, string $feature): array
    {
        // Tenant EXPLICITE : l'Organization de l'article dont le contenu est
        // lu/ecrit — jamais `currentOrganization() ?? user.organization_id`.
        $organization = $this->tenantOf($post);
        $process = AiProcess::fromFeature($feature);

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

        $apiKey = (string) ($config['api_key'] ?? '');
        $baseUrl = $config['base_url'] ?? 'https://api.openai.com/v1';
        $timeout = (int) ($config['timeout'] ?? self::TIMEOUT);

        // Une cle absente est une indisponibilite AVANT tout appel et avant
        // toute ecriture — comme un credential tenant absent sur les chemins
        // canoniques (code stable `ai_not_configured`).
        if ($provider !== 'ollama' && trim($apiKey) === '') {
            throw AiRefusedException::notConfigured(
                new \RuntimeException('Cle API manquante pour le provider '.$provider.'.')
            );
        }

        // Preuve du credential : la cle vient de la configuration PLATEFORME,
        // lue ci-dessus — la primitive la declare, le ledger ne la deduit pas.
        $resolved = new ResolvedModel(
            (string) $provider,
            (string) $model,
            ProviderResolver::declareLegacyPlatformCredential((string) $provider, keyless: $provider === 'ollama'),
        );

        // GARDE AVANT PROVIDER : budget Organization, budget/quota du process,
        // credit du demandeur. Un refus n'ecrit rien : ni ledger, ni trace,
        // ni quota — un appel qui n'est pas parti n'est pas une utilisation.
        $verdict = $this->economicGuard->authorize(
            $organization,
            $process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.blog.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.blog.economic_guard.monthly_unknown_limit', 10),
            $user,
        );

        if (! $verdict->allowed) {
            throw AiRefusedException::fromVerdict($verdict);
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Tu es un assistant spécialisé dans la rédaction et la correction d\'articles de blog en français.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'temperature' => 0.7,
        ];

        $startedAt = microtime(true);
        $correlationId = AiCorrelation::id();

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
                // Ollama tourne en local : coût nul réel, déclaré `free` au
                // catalogue (TASK-1132).
                $usage = AiUsage::fromOllamaGenerate($response->json());
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

                $response = $http->post(rtrim($baseUrl, '/').'/chat/completions', $payload);

                if (! $response->successful()) {
                    $apiError = $response->json('error') ?? $response->json('error')['message'] ?? "Erreur IA (HTTP {$response->status()})";
                    $errorMessage = is_string($apiError) ? $apiError : (is_array($apiError) ? ($apiError['message'] ?? "Erreur IA (HTTP {$response->status()})") : "Erreur IA (HTTP {$response->status()})");
                    throw new \RuntimeException($errorMessage);
                }

                $body = $response->json();
                $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
                // TASK-1132 : `$config['input_price_per_1m'] ?? 0` fabriquait un
                // coût de 0 dès que le provider n'était pas OpenAI, car seul le
                // bloc `openai` portait un prix. Le catalogue tranche désormais.
                $usage = AiUsage::fromChatCompletions($body);
            }
        } catch (\Throwable $exception) {
            // L'appel est PARTI : c'est une tentative economiquement reelle,
            // elle a sa ligne de ledger (cout NULL / unknown, jamais 0 invente).
            // Aucune trace produit ni quota article : un echec n'est pas un
            // article genere. L'exception d'origine est conservee en cause.
            $this->recordLedger($organization, $user, $process, $feature, $resolved,
                AiUsage::notObserved(), null, AiProviderInvocation::STATUS_FAILED,
                $correlationId, $exception::class, $startedAt);

            if ($exception instanceof ConnectionException) {
                throw new \RuntimeException('Connexion au service IA impossible.', 0, $exception);
            }

            throw $exception;
        }

        // TASK-1132 : le catalogue tranche (usage observe x tarif), sinon UNKNOWN.
        $cost = AiPricingCatalog::cost($provider, $model, $usage);

        $this->recordLedger($organization, $user, $process, $feature, $resolved,
            $usage, $cost, AiProviderInvocation::STATUS_SUCCESS, $correlationId, null, $startedAt);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $interaction = AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'correlation_id' => $correlationId,
            'process' => $process,
            'feature' => $feature,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $text,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$cost->traceAttributes(),
            'metadata' => [
                'blog_post_id' => $post->id,
                'latency_ms' => $latencyMs,
                'provider' => $provider,
                // TASK-1247 : le payeur, lisible sur la trace produit aussi.
                'credential_source' => ProviderResolver::credentialSourceFor($resolved->instance),
            ],
        ]);

        return [
            'content' => $text,
            'provider' => $provider,
            'model' => $model,
            'ai_interaction_id' => $interaction->id,
        ];
    }

    /**
     * L'Organization de l'article : le tenant de toute trace de ce chemin.
     * Un article sans Organization n'a pas de tenant, donc pas d'IA — c'est
     * un defaut de l'appelant, pas un cas a deviner.
     */
    private function tenantOf(BlogPost $post): Organization
    {
        $organizationId = trim((string) $post->organization_id);

        if ($organizationId === '') {
            throw new \RuntimeException('Blog AI requires an article attached to an Organization.');
        }

        return Organization::query()->findOrFail($organizationId);
    }

    /**
     * Ligne canonique du ledger `ai_provider_invocations` — une par appel
     * provider reellement tente. `capability` NULL : ce chemin n'est pas une
     * capability canonique (il le dit tel quel) ; `feature` porte la fonction
     * Blog emettrice, `process` son identifiant stable.
     */
    private function recordLedger(
        Organization $organization,
        User $user,
        string $process,
        string $feature,
        ResolvedModel $resolved,
        AiUsage $usage,
        ?AiCost $cost,
        string $status,
        string $correlationId,
        ?string $failureReason,
        float $startedAt,
    ): void {
        $this->ledger->recordGeneration(
            organizationId: (string) $organization->id,
            userId: (string) $user->id,
            capability: null,
            process: $process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $correlationId,
            sdkInvocationId: null,
            failureReason: $failureReason,
            startedAtMicrotime: $startedAt,
            feature: $feature,
        );
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

    private function buildResult(array $callResult, User $user, string $feature, ?string $title = null, ?string $summary = null, ?string $content = null): array
    {
        $orgId = currentOrganization()?->id ?? $user->organization_id;
        $config = BlogAiConfig::forOrganization($orgId);

        $limit = $feature === 'blog_generate' ? $config->generate_limit : $config->correct_limit;
        $cleanedContent = $content !== null
            ? $this->cleanGeneratedArticleHtml($content, $title, $summary)
            : ($feature === 'blog_generate'
                ? $this->cleanGeneratedArticleHtml($callResult['content'], $title, $summary)
                : $callResult['content']);

        $result = [
            'content' => $cleanedContent,
            'provider' => $callResult['provider'],
            'model' => $callResult['model'],
            'limit' => $limit,
        ];

        if ($title !== null && $feature === 'blog_generate') {
            $result['title'] = $title;
        }
        if ($summary !== null && $feature === 'blog_generate') {
            $result['summary'] = $summary;
        }

        return $result;
    }

    private function parseGenerateResponse(string $raw, ?string $fallbackTitle, ?string $fallbackSummary): array
    {
        $text = trim($raw);

        // Strip markdown fences before JSON decode (AI models often wrap JSON in ```json ... ```)
        if (preg_match('/^\s*```(?:json)?\s*\n(.*?)\n\s*```\s*$/is', $text, $fenceMatches)) {
            $text = trim($fenceMatches[1]);
        } elseif (preg_match('/^\s*```(?:json)?\s*\n/i', $text)) {
            $text = preg_replace('/^\s*```(?:json)?\s*\n/i', '', $text);
            $text = preg_replace('/\n\s*```\s*$/i', '', $text);
            $text = trim($text);
        }

        // Try direct decode first (works for well-formed JSON)
        $json = json_decode($text, true);

        // If decode fails, fix malformed JSON from AI models:
        // 1. Replace backslash+newline with \n escape
        // 2. Replace raw newlines inside JSON string values with \n escape
        if (! is_array($json) || ! isset($json['content'])) {
            $fixed = str_replace("\\\n", '\\n', $text);

            $fixed = preg_replace_callback(
                '/"(?:[^"\\\\]|\\\\.)*"/s',
                function ($m) {
                    return '"'.str_replace(["\n", "\r"], ['\\n', '\\r'], substr($m[0], 1, -1)).'"';
                },
                $fixed
            );

            $json = json_decode($fixed, true);
        }

        // If still failed, AI may have produced JSON with unescaped quotes in HTML content.
        // Extract title/summary with simple regex, content by position.
        if (! is_array($json) || ! isset($json['content'])) {
            $json = $this->extractFieldsFromMalformedJson($text);
        }

        if (! is_array($json) || ! isset($json['content'])) {
            $cleaned = $this->cleanGeneratedArticleHtml($raw);
            $result = $this->stripTitleSummaryFromHtml($cleaned, $fallbackTitle, $fallbackSummary);
            $result = $this->normalizeHeadingLevels($result);

            return [
                'title' => $fallbackTitle,
                'summary' => $fallbackSummary,
                'content' => $result,
            ];
        }

        $title = isset($json['title']) && is_string($json['title']) && trim($json['title']) !== ''
            ? trim($json['title'])
            : $fallbackTitle;
        $summary = isset($json['summary']) && is_string($json['summary']) && trim($json['summary']) !== ''
            ? trim($json['summary'])
            : $fallbackSummary;
        $content = is_string($json['content']) ? $json['content'] : '';

        return [
            'title' => $title,
            'summary' => $summary,
            'content' => $content,
        ];
    }

    /**
     * Extract title/summary/content from malformed AI JSON where content
     * contains unescaped quotes that break standard json_decode().
     */
    private function extractFieldsFromMalformedJson(string $text): ?array
    {
        // title and summary are simple strings without quotes — safe to regex
        if (! preg_match('/"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $text, $tm)) {
            return null;
        }
        if (! preg_match('/"summary"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $text, $sm)) {
            return null;
        }

        // content may contain unescaped quotes — extract by position
        // Find "content": " and grab everything until the closing } of the JSON object
        if (! preg_match('/"content"\s*:\s*"/i', $text, $cm, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $cm[0][1] + strlen($cm[0][0]);
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        // Content is between the opening " and the last " before }
        $rawContent = substr($text, $start, $end - $start);
        // Strip trailing quote+whitespace before the closing brace
        $rawContent = preg_replace('/"\s*$/s', '', $rawContent);
        // Convert escaped newlines to real newlines
        $rawContent = str_replace(['\\n', '\\r'], ["\n", "\r"], $rawContent);

        return [
            'title' => $tm[1],
            'summary' => $sm[1],
            'content' => $rawContent,
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

        $html = $this->stripPositionalTitleSummary($html, $title, $summary);

        $html = $this->normalizeHeadingLevels($html);

        return trim($html);
    }

    private function stripPositionalTitleSummary(string $html, ?string $title, ?string $summary): string
    {
        $html = $this->stripFirstTagMatch($html, $title, ['h1', 'h2']);
        $html = $this->stripFirstTagMatch($html, $summary, ['p']);

        return $html;
    }

    private function stripFirstTagMatch(string $html, ?string $referenceText, array $tags): string
    {
        if (empty($referenceText)) {
            return $html;
        }

        $normalized = preg_replace('/^\s+/', '', $html);
        $inner = $normalized;
        $wrapperLen = 0;
        if (preg_match('/^<(?:div|article|section)(?:\s[^>]*)?>\s*/is', $inner, $w)) {
            $wrapperLen = strlen($w[0]);
            $inner = substr($inner, $wrapperLen);
        }

        $tagPattern = implode('|', $tags);
        if (! preg_match('/^<('.$tagPattern.')[^>]*>(.*?)<\/\1>/is', $inner, $m)) {
            return $html;
        }

        $tagText = trim(strip_tags($m[2]));
        $words = array_filter(preg_split('/\s+/u', trim($referenceText), -1, PREG_SPLIT_NO_EMPTY), fn ($w) => mb_strlen($w) > 2);
        if (empty($words)) {
            return $html;
        }

        $matchCount = 0;
        foreach ($words as $word) {
            if (mb_stripos($tagText, $word) !== false) {
                $matchCount++;
            }
        }

        if ($matchCount / count($words) < 0.6) {
            return $html;
        }

        $fullMatchLen = $wrapperLen + strlen($m[0]);

        return preg_replace('/^.{'.$fullMatchLen.'}/s', '', preg_replace('/^\s+/', '', $html));
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
