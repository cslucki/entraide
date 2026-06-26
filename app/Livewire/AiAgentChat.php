<?php

namespace App\Livewire;

use App\Models\MemberAiProfile;
use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Support\Str;
use Livewire\Component;

class AiAgentChat extends Component
{
    public User $targetUser;

    public ?MemberAiProfile $profile = null;

    public string $question = '';

    public array $messages = [];

    public bool $isTyping = false;

    public ?string $error = null;

    private ?ProfileAgentConversation $conversation = null;

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

        $this->conversation = $this->findOrCreateConversation();

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

        $this->storeMessage('user', $question);

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

            $this->storeMessage('assistant', $response, $result);
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

        if ($this->conversation) {
            $this->conversation->messages()->delete();
            $this->conversation->delete();
            $this->conversation = null;
        }

        $this->conversation = $this->findOrCreateConversation();

        $this->messages[] = [
            'role' => 'assistant',
            'text' => "Bonjour ! 👋 Je suis l'agent IA de **{$this->targetUser->name}**. Je peux vous parler de ses compétences, de son expérience et de comment il peut vous aider. Que souhaitez-vous savoir ?",
            'time' => now()->format('H:i'),
        ];
    }

    private function findOrCreateConversation(): ProfileAgentConversation
    {
        $organization = currentOrganization()
            ?? $this->targetUser?->organization
            ?? DefaultOrganizationResolver::resolve();

        $visitorUserId = auth()->id();
        $visitorSessionId = ! $visitorUserId ? $this->resolveSessionId() : null;

        $query = ProfileAgentConversation::where('member_ai_profile_id', $this->profile->id)
            ->where('profile_owner_user_id', $this->targetUser->id)
            ->where('organization_id', $organization?->id);

        if ($visitorUserId) {
            $query->where('visitor_user_id', $visitorUserId);
        } else {
            $query->where('visitor_session_id', $visitorSessionId);
        }

        return $query->firstOrCreate([
            'organization_id' => $organization?->id,
            'member_ai_profile_id' => $this->profile->id,
            'profile_owner_user_id' => $this->targetUser->id,
            'visitor_user_id' => $visitorUserId,
            'visitor_session_id' => $visitorSessionId,
        ]);
    }

    private function resolveSessionId(): string
    {
        $key = 'profile_agent_visitor_id';

        if (! session()->has($key)) {
            session([$key => (string) Str::uuid()]);
        }

        return session($key);
    }

    private function loadMessages(): void
    {
        if (! $this->conversation) {
            return;
        }

        $dbMessages = ProfileAgentMessage::where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->get();

        foreach ($dbMessages as $msg) {
            $this->messages[] = [
                'role' => $msg->role,
                'text' => $msg->content,
                'time' => $msg->created_at->format('H:i'),
            ];
        }
    }

    private function storeMessage(string $role, string $content, ?array $result = null): void
    {
        if (! $this->conversation) {
            $this->conversation = $this->findOrCreateConversation();
        }

        ProfileAgentMessage::create([
            'conversation_id' => $this->conversation->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $result ? [
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'fields' => $result['fields'] ?? [],
            ] : null,
        ]);
    }

    public function render()
    {
        return view('livewire.ai-agent-chat');
    }
}
