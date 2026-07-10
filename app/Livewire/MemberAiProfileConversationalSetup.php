<?php

namespace App\Livewire;

use App\Models\MemberAiProfile;
use App\Services\Ai\JsonResponseParser;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\SupervisionProviderResolver;
use Livewire\Component;

class MemberAiProfileConversationalSetup extends Component
{
    public array $messages = [];

    public string $currentInput = '';

    public bool $isTyping = false;

    public int $turnCount = 0;

    public ?MemberAiProfile $profile = null;

    public ?array $previewData = null;

    public bool $showPreview = false;

    public string $provider = '';

    public string $model = '';

    public bool $started = false;

    public bool $saving = false;

    public ?string $error = null;

    public const MAX_TURNS = 10;

    public function mount(): void
    {
        $organization = currentOrganization();
        $user = auth()->user();

        if (! $user || ! $organization) {
            return;
        }

        $this->profile = MemberAiProfile::forUser($user)
            ->forOrganization($organization)
            ->first();

        $resolver = app(SupervisionProviderResolver::class);
        $providers = $resolver->availableProviders();
        $this->provider = $resolver->defaultProvider() ?? array_key_first($providers) ?? '';

        $defaultModel = $resolver->providerConfig($this->provider)['model'] ?? null;
        $firstModel = array_key_first($providers[$this->provider]['models'] ?? []);
        $this->model = $firstModel ?? $defaultModel ?? 'gpt-4o-mini';
    }

    public function start(): void
    {
        $this->resetExcept(['profile', 'provider', 'model']);
        $this->started = true;
        $this->isTyping = true;

        try {
            $responder = app(MemberProfileAgentResponder::class);

            $initialMessages = [];

            if ($this->profile && $this->profile->structured_profile) {
                $existing = $this->profile->structured_profile;
                $initialMessages[] = [
                    'role' => 'user',
                    'content' => 'Bonjour, je souhaite mettre à jour mon profil IA. Voici mon profil actuel : '
                        .json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        ."\n\nAide-moi à l\'améliorer.",
                ];
            } else {
                $initialMessages[] = [
                    'role' => 'user',
                    'content' => 'Bonjour, je suis prêt à créer mon profil IA.',
                ];
            }

            $result = $responder->chatWithSetupPrompt($initialMessages, $this->provider, $this->model);

            $responder->logSetupInteraction(
                $initialMessages[0]['content'],
                $result['response'],
                $result,
                $this->profile,
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $result['response']];
        } catch (\Throwable $e) {
            $this->error = 'Impossible de démarrer la conversation. Vérifiez la configuration IA.';
        } finally {
            $this->isTyping = false;
        }
    }

    public function send(): void
    {
        $this->error = null;

        $input = trim($this->currentInput);

        if ($input === '') {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $input];
        $this->currentInput = '';
        $this->turnCount++;
        $this->isTyping = true;

        try {
            $responder = app(MemberProfileAgentResponder::class);

            $chatMessages = array_map(
                fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
                $this->messages,
            );

            $result = $responder->chatWithSetupPrompt($chatMessages, $this->provider, $this->model);

            $responder->logSetupInteraction($input, $result['response'], $result, $this->profile);

            $responseText = $result['response'];
            $this->messages[] = ['role' => 'assistant', 'content' => $responseText];

            if ($this->turnCount >= self::MAX_TURNS) {
                $this->enterPreviewFallback();
            } else {
                $this->tryEnterPreview($responseText);
            }
        } catch (\Throwable $e) {
            $this->error = 'Une erreur est survenue. Veuillez réessayer.';
        } finally {
            $this->isTyping = false;
        }
    }

    public function validateAndSave(): void
    {
        $this->saving = true;

        try {
            $organization = currentOrganization();
            $user = auth()->user();

            if (! $this->profile) {
                $this->profile = MemberAiProfile::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'status' => MemberAiProfile::STATUS_DRAFT,
                    'locale' => 'fr',
                ]);
            }

            $data = [
                'structured_profile' => $this->previewData,
                'wizard_state' => ['setup_method' => 'conversational', 'completed_at' => now()->toIso8601String()],
                'last_saved_at' => now(),
            ];

            if (isset($this->previewData['summary'])) {
                $data['member_profile_summary'] = $this->previewData['summary'];
            }

            $this->profile->update($data);

            $this->dispatch('profile-saved');

            $this->previewData = null;
            $this->showPreview = false;
        } catch (\Throwable $e) {
            $this->error = 'Erreur lors de la sauvegarde.';
        } finally {
            $this->saving = false;
        }
    }

    public function restart(): void
    {
        $this->messages = [];
        $this->currentInput = '';
        $this->turnCount = 0;
        $this->previewData = null;
        $this->showPreview = false;
        $this->error = null;
        $this->started = false;

        $this->start();
    }

    public function abandon()
    {
        return redirect()->route('agent-ia.wizard');
    }

    private function tryEnterPreview(string $responseText): void
    {
        try {
            $json = JsonResponseParser::extractJsonFromText($responseText);
            $data = json_decode($json, true);

            if (! is_array($data)) {
                return;
            }

            $requiredKeys = ['summary', 'service_scope', 'skills'];

            $hasRequired = true;

            foreach ($requiredKeys as $key) {
                if (! isset($data[$key])) {
                    $hasRequired = false;
                    break;
                }
            }

            if ($hasRequired) {
                $this->previewData = $data;
                $this->showPreview = true;
            }
        } catch (\Throwable) {
            // No valid JSON yet, continue conversation
        }
    }

    private function enterPreviewFallback(): void
    {
        $lastMessage = end($this->messages);

        try {
            $json = JsonResponseParser::extractJsonFromText($lastMessage['content']);
            $data = json_decode($json, true);
            $this->previewData = is_array($data) ? $data : null;
        } catch (\Throwable) {
            $this->previewData = [
                'summary' => $lastMessage['content'],
                'note' => 'Le format JSON n\'a pas pu être extrait automatiquement. Vous pouvez ajuster les champs ci-dessous.',
            ];
        }

        $this->showPreview = true;
    }

    public function render()
    {
        return view('livewire.member-ai-profile-conversational-setup');
    }
}
