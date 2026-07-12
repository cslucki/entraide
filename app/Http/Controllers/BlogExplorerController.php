<?php

namespace App\Http\Controllers;

use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\BlogAnalysisNote;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Http;

class BlogExplorerController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('throttle:20,1', only: ['chat', 'generateNote']),
        ];
    }

    private const MAX_EXCHANGES = 50;

    private const MAX_NOTE_CHARS = 3000;

    private const MAX_OUTPUT_TOKENS = 512;

    private const TIMEOUT = 30;

    private const ALLOWED_NOTE_TAGS = '<h2><h3><h4><p><ul><ol><li><strong><em><b><i><u><br><blockquote>';


    public function chat(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if ($user->id !== $post->user_id && ! $user->is_admin && ! $this->isCoAuthor($post, $user)) {
            abort(403);
        }

        if (! $this->hasSavedArticleContent($post)) {
            return response()->json([
                'text' => __('blog.explorer_article_not_saved'),
            ]);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'messages' => ['sometimes', 'array', 'max:'.self::MAX_EXCHANGES],
            'messages.*.role' => ['required_with:messages', 'string', 'in:user,assistant'],
            'messages.*.text' => ['required_with:messages', 'string'],
        ]);

        $conversationMessages = $data['messages'] ?? [];

        $systemPrompt = $this->buildExplorerSystemPrompt($post);
        $userMessage = $data['message'];

        $aiResponse = $this->callAiForDialogue($post, $user, $systemPrompt, $conversationMessages, $userMessage);

        return response()->json([
            'text' => $aiResponse['content'],
        ]);
    }

    public function generateNote(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if ($user->id !== $post->user_id && ! $user->is_admin && ! $this->isCoAuthor($post, $user)) {
            abort(403);
        }

        if (! $this->hasSavedArticleContent($post)) {
            return response()->json([
                'error' => __('blog.explorer_article_not_saved'),
            ], 422);
        }

        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.self::MAX_EXCHANGES],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.text' => ['required', 'string'],
        ]);

        $conversationMessages = $data['messages'];

        $locale = app()->getLocale();
        $scenarioId = 'blog_explorer_note_'.$locale;
        $promptTemplate = $this->resolvePrompt($scenarioId, 'blog_explorer_note_fr');
        $articleContext = $this->articleContext($post);
        $notePrompt = "ARTICLE SAUVEGARDÉ :\n\n{$articleContext}\n\n---\n\nHISTORIQUE DE LA CONVERSATION :\n\n";
        foreach ($conversationMessages as $msg) {
            $role = $msg['role'] === 'user' ? 'Utilisateur' : 'Assistant';
            $notePrompt .= "{$role} : {$msg['text']}\n\n";
        }
        $notePrompt .= "\n---\n\n{$promptTemplate}";

        $aiResponse = $this->callAiSimple($post, $user, $notePrompt, 'blog_explorer_note');

        $noteContent = $this->cleanGeneratedNoteHtml($aiResponse);

        $noteLength = mb_strlen(strip_tags($noteContent));
        if ($noteLength < 150 || $noteLength > self::MAX_NOTE_CHARS) {
            return response()->json([
                'error' => __('blog.explorer_deep_chat_error'),
                'note' => $noteContent,
                'length' => $noteLength,
            ], 422);
        }

        return response()->json([
            'note' => $noteContent,
            'length' => $noteLength,
        ]);
    }

    public function indexNotes(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if ($user->id !== $post->user_id && ! $user->is_admin && ! $this->isCoAuthor($post, $user)) {
            abort(403);
        }

        $notes = BlogAnalysisNote::where('blog_post_id', $post->id)
            ->where('organization_id', $organization->id)
            ->with('user:id,first_name,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'notes' => $notes->map(fn (BlogAnalysisNote $n) => [
                'id' => $n->id,
                'note_content' => $n->note_content,
                'method' => $n->method,
                'metadata' => $n->metadata,
                'created_at' => $n->created_at->diffForHumans(),
                'user_name' => $n->user?->fullName ?? __('blog.legend_deleted_user'),
            ]),
        ]);
    }

    public function storeNote(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if ($user->id !== $post->user_id && ! $user->is_admin && ! $this->isCoAuthor($post, $user)) {
            abort(403);
        }

        $data = $request->validate([
            'note_content' => ['required', 'string', 'min:150', 'max:6000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $noteContent = $this->cleanGeneratedNoteHtml($data['note_content']);

        $noteLength = mb_strlen(strip_tags($noteContent));
        if ($noteLength < 150 || $noteLength > self::MAX_NOTE_CHARS) {
            return response()->json([
                'message' => __('blog.explorer_note_save_error'),
            ], 422);
        }

        $note = BlogAnalysisNote::create([
            'blog_post_id' => $post->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'method' => 'explorer',
            'note_content' => $noteContent,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return response()->json([
            'id' => $note->id,
            'note_content' => $note->note_content,
            'created_at' => $note->created_at->diffForHumans(),
            'message' => __('blog.explorer_note_saved'),
        ]);
    }

    public function updateNote(Request $request, BlogPost $post, BlogAnalysisNote $note): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if ($note->blog_post_id !== $post->id || $note->organization_id !== $organization->id) {
            abort(404);
        }

        if ($user->id !== $note->user_id && ! $user->is_admin) {
            abort(403);
        }

        $data = $request->validate([
            'note_content' => ['required', 'string', 'min:150', 'max:6000'],
        ]);

        $noteContent = $this->cleanGeneratedNoteHtml($data['note_content']);

        $noteLength = mb_strlen(strip_tags($noteContent));
        if ($noteLength < 150 || $noteLength > self::MAX_NOTE_CHARS) {
            return response()->json([
                'message' => __('blog.explorer_note_save_error'),
            ], 422);
        }

        $note->update([
            'note_content' => $noteContent,
            'metadata' => array_merge($note->metadata ?? [], ['edited_at' => now()->toIso8601String()]),
        ]);

        return response()->json([
            'id' => $note->id,
            'note_content' => $note->note_content,
            'message' => __('blog.explorer_note_saved'),
        ]);
    }

    public function destroyNote(Request $request, BlogPost $post, BlogAnalysisNote $note): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if ($note->blog_post_id !== $post->id || $note->organization_id !== $organization->id) {
            abort(404);
        }

        if ($user->id !== $note->user_id && ! $user->is_admin) {
            abort(403);
        }

        $note->delete();

        return response()->json([
            'message' => __('blog.explorer_note_deleted'),
        ]);
    }

    public function orgChat(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->chat($request, $post);
    }

    public function orgGenerateNote(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->generateNote($request, $post);
    }

    public function orgIndexNotes(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->indexNotes($request, $post);
    }

    public function orgStoreNote(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->storeNote($request, $post);
    }

    public function orgUpdateNote(Request $request, string $org, BlogPost $post, BlogAnalysisNote $note): JsonResponse
    {
        return $this->updateNote($request, $post, $note);
    }

    public function orgDestroyNote(Request $request, string $org, BlogPost $post, BlogAnalysisNote $note): JsonResponse
    {
        return $this->destroyNote($request, $post, $note);
    }

    private function buildExplorerSystemPrompt(BlogPost $post): string
    {
        $locale = app()->getLocale();
        $scenarioId = 'blog_explorer_dialogue_'.$locale;
        $promptTemplate = $this->resolvePrompt($scenarioId, 'blog_explorer_dialogue_fr');

        return $promptTemplate."\n\n---\n\nARTICLE SAUVEGARDÉ À ANALYSER\n\n".$this->articleContext($post)."\n\nRègle impérative : tu as déjà accès à cet article sauvegardé. Ne demande jamais à l'utilisateur de te le fournir. Tes réponses doivent s'appuyer explicitement sur son titre, son résumé et son contenu.";
    }

    private function hasSavedArticleContent(BlogPost $post): bool
    {
        return trim(strip_tags((string) $post->content)) !== '';
    }

    private function articleContext(BlogPost $post): string
    {
        $title = trim((string) $post->title);
        $summary = trim((string) $post->summary);
        $content = trim(strip_tags((string) $post->content));

        return "Titre : {$title}\n\nRésumé : {$summary}\n\nContenu :\n{$content}";
    }

    private function resolvePrompt(string $scenarioId, ?string $fallbackId = null): string
    {
        $prompt = AdminAiPrompt::where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderBy('version', 'desc')
            ->first();

        if (! $prompt && $fallbackId) {
            $prompt = AdminAiPrompt::where('scenario_id', $fallbackId)
                ->where('is_active', true)
                ->orderBy('version', 'desc')
                ->first();
        }

        if ($prompt) {
            return $prompt->prompt_text;
        }

        return "Tu es un assistant d'écriture. Lis l'article ci-dessous et aide l'utilisateur à l'explorer en profondeur.\n\nArticle : %s\n\nContenu :\n%s";
    }

    private function callAiForDialogue(BlogPost $post, User $user, string $systemPrompt, array $conversationMessages, string $newMessage): array
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

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($conversationMessages as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['text'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $newMessage,
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'temperature' => 0.7,
        ];

        $startedAt = (int) (microtime(true) * 1000);

        try {
            if ($provider === 'ollama') {
                $allContent = '';
                foreach ($messages as $m) {
                    $allContent .= ($m['role'] === 'system' ? $m['content'] : "{$m['role']}: {$m['content']}\n\n");
                }

                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post(rtrim($baseUrl, '/').'/api/generate', [
                        'model' => $model,
                        'prompt' => $allContent,
                        'stream' => false,
                        'temperature' => 0.7,
                        'options' => ['num_predict' => self::MAX_OUTPUT_TOKENS],
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('Erreur IA (HTTP '.$response->status().')');
                }

                $text = trim((string) ($response->json('response') ?? ''));
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
                    $apiError = $response->json('error') ?? "Erreur IA (HTTP {$response->status()})";
                    $errorMessage = is_string($apiError) ? $apiError : ($apiError['message'] ?? "Erreur IA (HTTP {$response->status()})");
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

        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => currentOrganization()?->id ?? $user->organization_id,
            'feature' => 'blog_explorer',
            'model' => $provider.'/'.$model,
            'prompt' => $newMessage,
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
        ];
    }

    private function callAiSimple(BlogPost $post, User $user, string $prompt, string $feature): string
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
                ['role' => 'system', 'content' => 'Tu es un assistant spécialisé dans l\'analyse de textes et la relecture en français.'],
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
                        'prompt' => "Tu es un assistant spécialisé dans l'analyse de textes et la relecture en français.\n\n{$prompt}",
                        'stream' => false,
                        'temperature' => 0.7,
                        'options' => ['num_predict' => self::MAX_OUTPUT_TOKENS],
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('Erreur IA (HTTP '.$response->status().')');
                }

                $text = trim((string) ($response->json('response') ?? ''));
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
                    $apiError = $response->json('error') ?? "Erreur IA (HTTP {$response->status()})";
                    $errorMessage = is_string($apiError) ? $apiError : ($apiError['message'] ?? "Erreur IA (HTTP {$response->status()})");
                    throw new \RuntimeException($errorMessage);
                }

                $body = $response->json();
                $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
            }
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Connexion au service IA impossible.');
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        $inputTokens = 0;
        $outputTokens = 0;
        $costUsd = 0;

        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => currentOrganization()?->id ?? $user->organization_id,
            'feature' => $feature,
            'model' => $provider.'/'.$model,
            'prompt' => mb_substr($prompt, 0, 2000),
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

    private function cleanGeneratedNoteHtml(string $html): string
    {
        $html = preg_replace('/^\s*```[a-zA-Z]*\s*$/m', '', $html) ?? $html;
        $html = preg_replace('/```[a-zA-Z]*/', '', $html) ?? $html;
        $html = str_replace('**', '', $html);

        return trim(strip_tags($html, self::ALLOWED_NOTE_TAGS));
    }

    private function isCoAuthor(BlogPost $post, User $user): bool
    {
        return $post->coAuthors()->where('user_id', $user->id)->exists();
    }
}
