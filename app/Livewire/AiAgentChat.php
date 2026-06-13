<?php

namespace App\Livewire;

use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Livewire\Component;

class AiAgentChat extends Component
{
    public User $targetUser;

    public ?MemberAiProfile $profile = null;

    public string $question = '';

    public array $messages = [];

    public bool $isTyping = false;

    public ?string $error = null;

    public function mount(User $user): void
    {
        $this->targetUser = $user;

        $organization = currentOrganization()
            ?? $user?->organization
            ?? DefaultOrganizationResolver::resolve();

        if (! $organization) {
            return;
        }

        $this->profile = MemberAiProfile::where('user_id', $user->id)
            ->where('status', MemberAiProfile::STATUS_PUBLISHED)
            ->first();

        if (! $this->profile) {
            return;
        }

        $this->loadMessages();

        if (empty($this->messages)) {
            $this->messages[] = [
                'role' => 'assistant',
                'text' => "Bonjour ! 👋 Je suis l'agent IA de **{$user->name}**. Je peux vous parler de ses compétences, de son expérience et de comment il peut vous aider. Que souhaitez-vous savoir ?",
                'time' => now()->format('H:i'),
            ];
        }
    }

    public function sendMessage(): void
    {
        $this->error = null;

        if (! $this->profile) {
            $this->error = "Ce membre n'a pas encore publié son profil IA.";

            return;
        }

        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        $this->messages[] = [
            'role' => 'user',
            'text' => $question,
            'time' => now()->format('H:i'),
        ];

        $this->question = '';
        $this->isTyping = true;

        try {
            $responder = app(MemberProfileAgentResponder::class);
            $result = $responder->answerWithDefaultProvider($this->profile, $question);

            $response = $result['response'] ?? "Je n'ai pas pu générer une réponse pour le moment.";

            $this->messages[] = [
                'role' => 'assistant',
                'text' => $response,
                'time' => now()->format('H:i'),
            ];

            $this->logInteraction($question, $response, $result);
            $this->saveMessages();
        } catch (\Throwable $e) {
            $this->error = 'Une erreur est survenue. Veuillez réessayer.';
        } finally {
            $this->isTyping = false;
        }
    }

    public function resetConversation(): void
    {
        $this->messages = [];
        $this->error = null;
        $this->question = '';

        if (auth()->check()) {
            MemberAiProfileInteraction::where('member_ai_profile_id', $this->profile?->id)
                ->where('visitor_user_id', auth()->id())
                ->delete();
        } else {
            session()->forget('ai_agent_chat_'.$this->profile?->id);
        }

        $this->messages[] = [
            'role' => 'assistant',
            'text' => "Bonjour ! 👋 Je suis l'agent IA de **{$this->targetUser->name}**. Je peux vous parler de ses compétences, de son expérience et de comment il peut vous aider. Que souhaitez-vous savoir ?",
            'time' => now()->format('H:i'),
        ];
    }

    private function loadMessages(): void
    {
        if (auth()->check()) {
            $interactions = MemberAiProfileInteraction::where('member_ai_profile_id', $this->profile->id)
                ->where('visitor_user_id', auth()->id())
                ->orderBy('created_at')
                ->get();

            foreach ($interactions as $interaction) {
                $this->messages[] = [
                    'role' => 'user',
                    'text' => $interaction->question,
                    'time' => $interaction->created_at->format('H:i'),
                ];
                $this->messages[] = [
                    'role' => 'assistant',
                    'text' => $interaction->response,
                    'time' => $interaction->created_at->format('H:i'),
                ];
            }
        } else {
            $this->messages = session('ai_agent_chat_'.$this->profile->id, []);
        }
    }

    private function saveMessages(): void
    {
        if (! auth()->check()) {
            session(['ai_agent_chat_'.$this->profile->id => $this->messages]);
        }
    }

    private function logInteraction(string $question, string $response, array $result): void
    {
        $organization = currentOrganization()
            ?? $this->targetUser?->organization
            ?? DefaultOrganizationResolver::resolve();

        $provider = $result['provider'] ?? 'unknown';

        MemberAiProfileInteraction::create([
            'organization_id' => $organization?->id ?? $this->profile?->organization_id,
            'member_ai_profile_id' => $this->profile?->id,
            'profile_owner_user_id' => $this->targetUser->id,
            'visitor_user_id' => auth()->id(),
            'visitor_type' => auth()->check() ? 'user' : 'guest',
            'provider' => $provider,
            'status' => 'success',
            'question' => $question,
            'response' => $response,
            'matched_fields' => $result['fields'] ?? [],
            'metadata' => [
                'model' => $result['model'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'scenario' => 'inline_member_presentation',
            ],
        ]);
    }

    public function render()
    {
        return view('livewire.ai-agent-chat');
    }
}
