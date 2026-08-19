<?php

namespace App\Http\Controllers;

use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogAnalysisNote;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\BlogAiService;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use App\Support\Ai\BlogExplorerFacilitation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * Explorer d'article (dialogue deep-chat + note d'analyse generee) — chemin
 * IA HERITE du Blog, cle plateforme, appels HTTP directs.
 *
 * TASK-1248 : l'AUTORITE ECONOMIQUE est fermee — et seulement elle, comme
 * TASK-1247 l'a fait pour `BlogAiService`. Les deux appels provider
 * (`callAiForDialogue()`, `callAiSimple()`) convergent vers UNE primitive
 * (`callProvider()`) : tenant = Organization de l'ARTICLE, credential
 * plateforme DECLARE (`ProviderResolver::declareLegacyPlatformCredential()`,
 * jamais deduit), `AiEconomicGuard::authorize()` AVANT tout `Http::post`
 * (budget mensuel Organization, budget/quota d'inconnus par process
 * `blog.explorer_*`, credit IA du demandeur), une ligne du ledger canonique
 * `ai_provider_invocations` par appel reellement tente (succes ET echec), et
 * la trace produit `ai_interactions` sur succes seulement (double ecriture,
 * zero double comptage). Un refus est un `AiRefusedException` code, rendu 429
 * JSON `{error, code, offers_url}` — jamais la forme `200 {text}` d'une
 * reponse IA normale. Le throttle `20,1` reste une preoccupation separee
 * (anti-abus de frequence) : il n'est ni fusionne ni remplace.
 *
 * Ce que TASK-1248 ne fait PAS (hors V1, BLOC E) : ni CapabilityRegistry, ni
 * Constitution/doctrine, ni ContextBuilder, ni credential BYOK Organization.
 * Le chat visiteur public anonyme et la famille C du gap analysis T1246 sont
 * hors perimetre (TASK dediee).
 *
 * TASK-1249 : les quatre methodes de facilitation de Roger (`explorer`,
 * `slow_down`, `clarifier`, `invent` — identifiants de
 * `BlogAiService::METHOD_SELECTION_METHODS`, reutilises tels quels) arrivent
 * dans `chat()` par un `method_code` EXPLICITE, choisi par un bouton et porte
 * par la conversation. Il n'influence QUE le prompt systeme construit AVANT
 * `callProvider()` : definition methodologique courte resolue par
 * `resolvePrompt()` (repository `AdminAiPrompt`, scenario
 * `blog_explorer_method_{method}_{locale}`, fallback `_fr`, puis fallback
 * code `BlogExplorerFacilitation::defaultPrompt()`, jamais vide) + regles de
 * facilitation communes (l'IA propose, l'humain agit) + article. Sans
 * `method_code`, le prompt generique historique est conserve tel quel.
 */
class BlogExplorerController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

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

    private const SIMPLE_SYSTEM_PROMPT = 'Tu es un assistant spécialisé dans l\'analyse de textes et la relecture en français.';

    public function chat(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if (! $this->canAccessPostExplorer($post, $user, $organization)) {
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
            // TASK-1249 : methode de facilitation de la CONVERSATION (bouton),
            // identifiants canoniques partages avec la suggestion sur passage.
            'method_code' => ['sometimes', 'nullable', 'string', Rule::in(BlogAiService::METHOD_SELECTION_METHODS)],
        ]);

        $conversationMessages = $data['messages'] ?? [];
        $methodCode = $data['method_code'] ?? null;

        $systemPrompt = $this->buildExplorerSystemPrompt($post, $methodCode);
        $userMessage = $data['message'];

        try {
            $text = $this->callAiForDialogue($post, $user, $systemPrompt, $conversationMessages, $userMessage);
        } catch (AiRefusedException $e) {
            // TASK-1248 : refus economique AVANT tout appel provider. Jamais
            // rendu en `200 {text}` — cette forme est celle d'une reponse IA
            // (et de « article non sauvegarde ») ; un refus ne doit jamais lui
            // ressembler. Le `responseInterceptor` deep-chat leve `error`.
            return $this->refusedResponse($e, $organization);
        }

        return response()->json([
            'text' => $text,
        ]);
    }

    public function generateNote(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $user = $request->user();

        if (! $this->canAccessPostExplorer($post, $user, $organization)) {
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

        try {
            $aiResponse = $this->callAiSimple($post, $user, $notePrompt, 'blog_explorer_note');
        } catch (AiRefusedException $e) {
            // TASK-1248 : meme contrat de refus que `chat()` (429 + code).
            return $this->refusedResponse($e, $organization);
        }

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

        if (! $this->canAccessPostExplorer($post, $user, $organization)) {
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

        if (! $this->canAccessPostExplorer($post, $user, $organization)) {
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

        if (! $this->canManageExplorerNote($note, $user, $organization)) {
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

        if (! $this->canManageExplorerNote($note, $user, $organization)) {
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

    private function canAccessPostExplorer(BlogPost $post, User $user, object $organization): bool
    {
        if (! $this->userBelongsToOrganization($user, $organization)) {
            return false;
        }

        return $user->id === $post->user_id || $user->is_admin || $this->isCoAuthor($post, $user);
    }

    private function canManageExplorerNote(BlogAnalysisNote $note, User $user, object $organization): bool
    {
        if (! $this->userBelongsToOrganization($user, $organization)) {
            return false;
        }

        return $user->id === $note->user_id || $user->is_admin;
    }

    private function userBelongsToOrganization(User $user, object $organization): bool
    {
        return (string) $user->organization_id === (string) $organization->id;
    }

    /**
     * Prompt systeme du dialogue Explorer.
     *
     * Sans methode : le scenario generique historique
     * (`blog_explorer_dialogue_{locale}`), inchange. Avec une methode
     * (TASK-1249) : la definition methodologique COURTE de la methode
     * (scenario `blog_explorer_method_{method}_{locale}` du repository
     * `AdminAiPrompt`, fallback `_fr`, puis fallback code, jamais vide),
     * suivie des regles de facilitation communes — ajoutees par le code, donc
     * toujours presentes quoi qu'un admin ecrive dans la definition —, puis
     * l'article. Le `method_code` n'a aucun autre effet : `callProvider()`
     * (garde economique, ledger, trace) ne le voit pas.
     */
    private function buildExplorerSystemPrompt(BlogPost $post, ?string $methodCode = null): string
    {
        $locale = app()->getLocale();

        if ($methodCode !== null && BlogExplorerFacilitation::isValid($methodCode)) {
            $promptTemplate = $this->resolvePrompt(
                BlogExplorerFacilitation::scenarioId($methodCode, $locale),
                BlogExplorerFacilitation::scenarioId($methodCode, 'fr'),
                BlogExplorerFacilitation::defaultPrompt($methodCode, $locale),
            )."\n\n---\n\n".BlogExplorerFacilitation::facilitationRules($locale);
        } else {
            $scenarioId = 'blog_explorer_dialogue_'.$locale;
            $promptTemplate = $this->resolvePrompt($scenarioId, 'blog_explorer_dialogue_fr');
        }

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

    /**
     * Le repository de prompts existant (`AdminAiPrompt`, version active la
     * plus haute) : scenario demande, puis scenario de repli, puis `$default`
     * code quand l'appelant en fournit un (TASK-1249), sinon le texte
     * generique historique.
     */
    private function resolvePrompt(string $scenarioId, ?string $fallbackId = null, ?string $default = null): string
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

        if ($default !== null && trim($default) !== '') {
            return $default;
        }

        return "Tu es un assistant d'écriture. Lis l'article ci-dessous et aide l'utilisateur à l'explorer en profondeur.\n\nArticle : %s\n\nContenu :\n%s";
    }

    /**
     * Refus economique (TASK-1248) : reponse STRUCTUREE, code HTTP distinct,
     * meme contrat JSON que `BlogController` (T1247) et
     * `LoopController::knowledge()` — `{error, code, offers_url}`. Rien n'a
     * ete consomme, rien n'a ete ecrit.
     */
    private function refusedResponse(AiRefusedException $exception, Organization $organization): JsonResponse
    {
        return response()->json([
            'error' => $exception->getMessage(),
            'code' => $exception->refusalCode,
            'offers_url' => $exception->offersUrl($organization),
        ], 429);
    }

    /**
     * Dialogue Explorer : l'historique deep-chat + le nouveau message, sous
     * l'autorite economique via `callProvider()`.
     *
     * @param  list<array{role: string, text: string}>  $conversationMessages
     *
     * @throws AiRefusedException refus economique AVANT l'appel (code stable)
     * @throws \RuntimeException echec provider (deja tente, ledger ecrit)
     */
    private function callAiForDialogue(BlogPost $post, User $user, string $systemPrompt, array $conversationMessages, string $newMessage): string
    {
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

        // Ollama (/api/generate) : la conversation aplatie, comme avant.
        $ollamaPrompt = '';
        foreach ($messages as $m) {
            $ollamaPrompt .= ($m['role'] === 'system' ? $m['content'] : "{$m['role']}: {$m['content']}\n\n");
        }

        return $this->callProvider($post, $user, 'blog_explorer', $messages, $ollamaPrompt, $newMessage);
    }

    /**
     * Note d'analyse : un prompt systeme fixe + le prompt construit, sous
     * l'autorite economique via `callProvider()`.
     *
     * @throws AiRefusedException refus economique AVANT l'appel (code stable)
     * @throws \RuntimeException echec provider (deja tente, ledger ecrit)
     */
    private function callAiSimple(BlogPost $post, User $user, string $prompt, string $feature): string
    {
        $messages = [
            ['role' => 'system', 'content' => self::SIMPLE_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $prompt],
        ];

        return $this->callProvider(
            $post,
            $user,
            $feature,
            $messages,
            self::SIMPLE_SYSTEM_PROMPT."\n\n{$prompt}",
            mb_substr($prompt, 0, 2000),
        );
    }

    /**
     * UN appel provider, sous l'autorite economique (TASK-1248) — le point de
     * passage unique des deux fonctions Explorer, comme `BlogAiService::callAi()`.
     *
     * Ordre garanti : tenant -> provider/modele/credential plateforme ->
     * GARDE (aucun appel si refus, rien d'ecrit) -> appel HTTP -> ledger
     * (succes ou echec) -> trace produit (succes seulement).
     *
     * @param  list<array{role: string, content: string}>  $messages  messages chat/completions (systeme inclus)
     * @param  string  $ollamaPrompt  la meme conversation aplatie pour `/api/generate`
     * @param  string  $tracePrompt  ce que la trace produit `ai_interactions` conserve comme prompt
     *
     * @throws AiRefusedException refus economique AVANT l'appel (code stable)
     * @throws \RuntimeException echec provider (deja tente, ledger ecrit)
     */
    private function callProvider(BlogPost $post, User $user, string $feature, array $messages, string $ollamaPrompt, string $tracePrompt): string
    {
        // Tenant EXPLICITE : l'Organization de l'article explore — jamais
        // `currentOrganization() ?? user.organization_id`.
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
        // toute ecriture (code stable `ai_not_configured`) — plus jamais une
        // RuntimeException 500 apres construction de la requete.
        if ($provider !== 'ollama' && trim($apiKey) === '') {
            throw AiRefusedException::notConfigured(
                new \RuntimeException('Clé API manquante pour le provider '.$provider.'.')
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
        // credit du demandeur. Un refus n'ecrit rien : ni ledger, ni trace —
        // un appel qui n'est pas parti n'est pas une utilisation. Le throttle
        // `20,1` (frequence) est une autre preoccupation, laissee telle quelle.
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
            'messages' => $messages,
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
                        'prompt' => $ollamaPrompt,
                        'stream' => false,
                        'temperature' => 0.7,
                        'options' => ['num_predict' => self::MAX_OUTPUT_TOKENS],
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException('Erreur IA (HTTP '.$response->status().')');
                }

                $text = trim((string) ($response->json('response') ?? ''));
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
                    $apiError = $response->json('error') ?? "Erreur IA (HTTP {$response->status()})";
                    $errorMessage = is_string($apiError) ? $apiError : ($apiError['message'] ?? "Erreur IA (HTTP {$response->status()})");
                    throw new \RuntimeException($errorMessage);
                }

                $body = $response->json();
                $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
                // TASK-1132 : usage rapporte lu tel quel, le catalogue tranche.
                $usage = AiUsage::fromChatCompletions($body);
            }
        } catch (\Throwable $exception) {
            // L'appel est PARTI : c'est une tentative economiquement reelle,
            // elle a sa ligne de ledger (cout NULL / unknown, jamais 0 invente).
            // Aucune trace produit : un echec n'est pas une reponse Explorer.
            // L'exception d'origine est conservee en cause.
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

        AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'correlation_id' => $correlationId,
            'process' => $process,
            'feature' => $feature,
            'model' => $resolved->trace(),
            'prompt' => $tracePrompt,
            'response' => $text,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$cost->traceAttributes(),
            'metadata' => [
                'blog_post_id' => $post->id,
                'latency_ms' => $latencyMs,
                'provider' => $provider,
                // TASK-1248 : le payeur, lisible sur la trace produit aussi.
                'credential_source' => ProviderResolver::credentialSourceFor($resolved->instance),
            ],
        ]);

        return $text;
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
            throw new \RuntimeException('Blog Explorer AI requires an article attached to an Organization.');
        }

        return Organization::query()->findOrFail($organizationId);
    }

    /**
     * Ligne canonique du ledger `ai_provider_invocations` — une par appel
     * provider reellement tente. `capability` NULL : ce chemin n'est pas une
     * capability canonique (il le dit tel quel) ; `feature` porte la fonction
     * Explorer emettrice, `process` son identifiant stable.
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
