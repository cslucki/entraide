<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAiInteraction;
use App\Models\MemberAiProfile;
use App\Services\Ai\SupervisionProviderResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminMemberAiProfileController extends Controller
{
    public function __construct(
        protected SupervisionProviderResolver $resolver,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['status', 'search']);

        $profiles = MemberAiProfile::query()
            ->with(['user', 'organization'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('member_profile_summary', 'ilike', "%{$search}%")
                        ->orWhere('service_scope', 'ilike', "%{$search}%")
                        ->orWhereHas('user', fn ($query) => $query
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%"));
                });
            })
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        $statusCounts = MemberAiProfile::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.member-ai-profiles.index', [
            'profiles' => $profiles,
            'filters' => $filters,
            'statuses' => MemberAiProfile::$statuses,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function edit(MemberAiProfile $memberAiProfile): View
    {
        return $this->editView($memberAiProfile);
    }

    public function update(Request $request, MemberAiProfile $memberAiProfile): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(MemberAiProfile::$statuses)],
            'member_profile_summary' => ['nullable', 'string', 'max:2000'],
            'service_scope' => ['nullable', 'string', 'max:2000'],
            'experience_context' => ['nullable', 'string', 'max:4000'],
            'preferred_contact_action' => ['nullable', 'string', 'max:50'],
            'tone' => ['nullable', 'string', 'max:30'],
            'generated_summary' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'string', 'max:1000'],
            'help_types' => ['nullable', 'string', 'max:1000'],
            'boundaries' => ['nullable', 'string', 'max:1000'],
            'good_request_examples' => ['nullable', 'string', 'max:2000'],
            'bad_request_examples' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['skills'] = $this->linesToArray($validated['skills'] ?? null);
        $validated['help_types'] = $this->linesToArray($validated['help_types'] ?? null);
        $validated['boundaries'] = $this->linesToArray($validated['boundaries'] ?? null);
        $validated['good_request_examples'] = $this->linesToArray($validated['good_request_examples'] ?? null);
        $validated['bad_request_examples'] = $this->linesToArray($validated['bad_request_examples'] ?? null);

        $this->applyStatusDates($memberAiProfile, $validated['status']);
        $memberAiProfile->fill($validated);
        $memberAiProfile->last_saved_at = now();
        $memberAiProfile->save();

        return redirect()
            ->route('admin.member-ai-profiles.edit', $memberAiProfile)
            ->with('success', 'Agent profil IA mis à jour.');
    }

    public function publish(MemberAiProfile $memberAiProfile): RedirectResponse
    {
        $memberAiProfile->update([
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'validated_at' => $memberAiProfile->validated_at ?? now(),
            'published_at' => $memberAiProfile->published_at ?? now(),
            'disabled_at' => null,
        ]);

        return back()->with('success', 'Agent profil IA validé et publié.');
    }

    public function disable(MemberAiProfile $memberAiProfile): RedirectResponse
    {
        $memberAiProfile->update([
            'status' => MemberAiProfile::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);

        return back()->with('success', 'Agent profil IA désactivé.');
    }

    public function testLlm(Request $request, MemberAiProfile $memberAiProfile): View
    {
        $providers = $this->resolver->availableProviders();
        $providerNames = array_keys($providers);

        if (empty($providerNames)) {
            return $this->editView($memberAiProfile, [
                'llmTest' => [
                    'status' => 'error',
                    'error' => 'Aucun provider IA actif. Activez Ollama ou OpenRouter dans la configuration IA.',
                ],
            ]);
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($providerNames)],
            'model' => ['nullable', 'string', 'max:255'],
            'question' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $selectedProvider = $validated['provider'];
        $availableModels = array_keys($providers[$selectedProvider]['models'] ?? []);
        $selectedModel = $validated['model'] ?: ($availableModels[0] ?? $this->resolver->providerConfig($selectedProvider)['model']);

        if ($availableModels && ! in_array($selectedModel, $availableModels, true)) {
            abort(422, 'Modèle IA invalide pour ce provider.');
        }

        $startedAt = (int) (microtime(true) * 1000);
        $answer = null;
        $error = null;

        try {
            $answer = $this->callProfileTester($memberAiProfile, $selectedProvider, $selectedModel, $validated['question']);
        } catch (ConnectionException $e) {
            $error = 'Connexion impossible avec le provider '.$selectedProvider.'.';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;
        $status = $error ? 'error' : 'success';

        AdminAiInteraction::create([
            'organization_id' => $memberAiProfile->organization_id,
            'user_id' => auth()->id(),
            'scenario_id' => 'member_ai_profile_llm_test',
            'provider' => $selectedProvider,
            'model' => $selectedModel,
            'status' => $status,
            'input_excerpt' => Str::limit($validated['question'], 200),
            'input_hash' => hash('sha256', $validated['question']),
            'input_length' => strlen($validated['question']),
            'result_summary' => Str::limit($answer ?? $error ?? '', 500),
            'result_payload' => [
                'member_profile_id' => $memberAiProfile->id,
                'member_user_id' => $memberAiProfile->user_id,
                'question' => $validated['question'],
                'answer' => $answer,
                'error' => $error,
            ],
            'metadata' => [
                'source' => 'admin_member_ai_profile_tester',
                'provider_type' => $providers[$selectedProvider]['type'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ]);

        return $this->editView($memberAiProfile, [
            'llmTest' => [
                'status' => $status,
                'provider' => $selectedProvider,
                'providerLabel' => $providers[$selectedProvider]['label'] ?? $selectedProvider,
                'model' => $selectedModel,
                'question' => $validated['question'],
                'answer' => $answer,
                'error' => $error,
                'latencyMs' => $latencyMs,
            ],
            'selectedProvider' => $selectedProvider,
            'selectedModel' => $selectedModel,
            'testQuestion' => $validated['question'],
        ]);
    }

    private function editView(MemberAiProfile $memberAiProfile, array $data = []): View
    {
        $memberAiProfile->load(['user', 'organization']);
        $providers = $this->resolver->availableProviders();
        $defaultProvider = $this->resolver->defaultProvider() ?? array_key_first($providers);
        $defaultModel = $defaultProvider && isset($providers[$defaultProvider])
            ? array_key_first($providers[$defaultProvider]['models'])
            : '';

        return view('admin.member-ai-profiles.edit', array_merge([
            'profile' => $memberAiProfile,
            'statuses' => MemberAiProfile::$statuses,
            'providers' => $providers,
            'selectedProvider' => $defaultProvider ?? '',
            'selectedModel' => $defaultModel ?? '',
            'testQuestion' => "C'est quoi ta prestation ?",
            'llmTest' => null,
        ], $data));
    }

    private function callProfileTester(MemberAiProfile $profile, string $provider, string $model, string $question): string
    {
        $config = $this->resolver->providerConfig($provider);

        return match ($provider) {
            'ollama' => $this->callOllama($profile, $config, $model, $question),
            'openrouter' => $this->callOpenRouter($profile, $config, $model, $question),
            default => $this->callOpenAiCompatible($profile, $config, $model, $question),
        };
    }

    private function callOllama(MemberAiProfile $profile, array $config, string $model, string $question): string
    {
        $response = Http::timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/api/generate', [
                'model' => $model,
                'prompt' => $this->buildProfileTesterPrompt($profile, $question),
                'stream' => false,
                'think' => false,
                'options' => [
                    'num_predict' => 500,
                    'temperature' => 0.2,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse Ollama invalide (HTTP %d).', $response->status()));
        }

        $body = $response->json();

        return trim((string) ($body['response'] ?? $body['thinking'] ?? ''));
    }

    private function callOpenRouter(MemberAiProfile $profile, array $config, string $model, string $question): string
    {
        if (empty($config['api_key'])) {
            throw new \RuntimeException('Clé API OpenRouter manquante.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$config['api_key'],
            'HTTP-Referer' => config('ai.openrouter.site_url', config('app.url')),
            'X-Title' => config('ai.openrouter.site_name', config('app.name')),
        ])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $this->chatPayload($profile, $config, $model, $question));

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse OpenRouter invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function callOpenAiCompatible(MemberAiProfile $profile, array $config, string $model, string $question): string
    {
        if (empty($config['api_key'])) {
            throw new \RuntimeException('Clé API du provider manquante.');
        }

        $response = Http::withToken((string) $config['api_key'])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $this->chatPayload($profile, $config, $model, $question));

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse IA invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function chatPayload(MemberAiProfile $profile, array $config, string $model, string $question): array
    {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildProfileTesterSystemPrompt($profile)],
                ['role' => 'user', 'content' => $question],
            ],
            'max_tokens' => (int) ($config['max_output_tokens'] ?? 500),
            'temperature' => 0.2,
        ];
    }

    private function buildProfileTesterPrompt(MemberAiProfile $profile, string $question): string
    {
        return $this->buildProfileTesterSystemPrompt($profile)."\n\nQuestion utilisateur :\n".$question;
    }

    private function buildProfileTesterSystemPrompt(MemberAiProfile $profile): string
    {
        $profile->loadMissing(['user', 'organization']);

        $lines = [
            'Tu es l’agent IA de profil d’un membre BouclePro.',
            'Tu n\'es PAS le membre. Tu es un assistant IA qui présente le membre et son profil.',
            'Ne parle jamais à la première personne comme si tu étais le membre (ne dis pas "je suis").',
            'Réponds exclusivement en français, de manière courte, utile et factuelle.',
            'Tu dois répondre uniquement avec les informations du profil IA ci-dessous.',
            'N’invente aucune prestation, aucun tarif, aucune disponibilité et aucune coordonnée.',
            'Si la question sort du périmètre du profil IA, dis clairement que cela dépasse ton périmètre de présentation.',
            'Il ne s’agit pas d’une conversation persistante ni d’une marketplace.',
            '',
            'Profil IA :',
            '- Propriétaire du profil : '.($profile->user?->name ?? 'Utilisateur inconnu'),
            '- Organisation : '.($profile->organization?->name ?? 'Organisation inconnue'),
            '- Résumé : '.($profile->member_profile_summary ?: 'Non renseigné'),
            '- Résumé généré : '.($profile->generated_summary ?: 'Non renseigné'),
            '- Périmètre d’aide / prestation : '.($profile->service_scope ?: 'Non renseigné'),
            '- Contexte d’expérience : '.($profile->experience_context ?: 'Non renseigné'),
            '- Compétences : '.$this->formatProfileValue($profile->skills),
            '- Types d’aide : '.$this->formatProfileValue($profile->help_types),
            '- Limites : '.$this->formatProfileValue($profile->boundaries),
            '- Public cible : '.$this->formatProfileValue($profile->target_audience),
            '- Problèmes aidés : '.$this->formatProfileValue($profile->problems_helped),
            '- Bons exemples de demande : '.$this->formatProfileValue($profile->good_request_examples),
            '- Demandes hors périmètre : '.$this->formatProfileValue($profile->bad_request_examples),
            '- Ton : '.($profile->tone ?: 'Non renseigné'),
            '- Contact préféré : '.($profile->preferred_contact_action ?: 'Non renseigné'),
        ];

        return implode("\n", $lines);
    }

    private function formatProfileValue(mixed $value): string
    {
        if (is_array($value)) {
            return $value ? implode(', ', $value) : 'Non renseigné';
        }

        return $value ? (string) $value : 'Non renseigné';
    }

    private function linesToArray(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function applyStatusDates(MemberAiProfile $profile, string $status): void
    {
        if ($status === MemberAiProfile::STATUS_PUBLISHED) {
            $profile->validated_at = $profile->validated_at ?? now();
            $profile->published_at = $profile->published_at ?? now();
            $profile->disabled_at = null;
        }

        if ($status === MemberAiProfile::STATUS_DISABLED) {
            $profile->disabled_at = $profile->disabled_at ?? now();
        }

        if ($status !== MemberAiProfile::STATUS_DISABLED) {
            $profile->disabled_at = null;
        }
    }
}
