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
    const MAX_VISITOR_TURNS = 8;

    public User $targetUser;

    public ?MemberAiProfile $profile = null;

    public string $question = '';

    public array $messages = [];

    public bool $isTyping = false;

    public ?string $error = null;

    public bool $maxTurnsReached = false;

    public int $visitorTurnCount = 0;

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
                'text' => $this->initialAssistantMessage($user),
                'time' => now()->format('H:i'),
            ];
        }

        $this->visitorTurnCount = ProfileAgentMessage::where('conversation_id', $this->conversation->id)
            ->where('role', 'user')
            ->count();

        $this->maxTurnsReached = $this->visitorTurnCount >= self::MAX_VISITOR_TURNS;
    }

    public function sendMessage(): void
    {
        $this->error = null;

        if (! $this->profile) {
            $this->error = __('ai.visitor_chat_profile_missing');

            return;
        }

        $question = trim($this->question);
        if ($question === '') {
            return;
        }

        if ($this->maxTurnsReached) {
            $this->error = __('ai.visitor_chat_max_turns_reached');

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
            $result = $responder->answerWithDefaultProvider($this->profile, $question, 'profile_agent_visitor_chat');

            $response = $result['response'] ?? __('ai.visitor_chat_generation_failed');

            $this->messages[] = [
                'role' => 'assistant',
                'text' => $response,
                'time' => now()->format('H:i'),
            ];

            $this->storeMessage('assistant', $response, $result);

            $responder->logVisitorInteraction($question, $response, $result);

            $this->visitorTurnCount++;
            $this->maxTurnsReached = $this->visitorTurnCount >= self::MAX_VISITOR_TURNS;
        } catch (\Throwable $e) {
            $this->error = __('ai.visitor_chat_error');
        } finally {
            $this->isTyping = false;
        }
    }

    public function resetConversation(): void
    {
        $this->messages = [];
        $this->error = null;
        $this->question = '';
        $this->maxTurnsReached = false;
        $this->visitorTurnCount = 0;

        if ($this->conversation) {
            $this->conversation->messages()->delete();
            $this->conversation->delete();
            $this->conversation = null;
        }

        $this->conversation = $this->findOrCreateConversation();

        $this->messages[] = [
            'role' => 'assistant',
            'text' => $this->initialAssistantMessage($this->targetUser),
            'time' => now()->format('H:i'),
        ];
    }

    private function initialAssistantMessage(User $user): string
    {
        return __('ai.visitor_chat_initial_message', ['member_name' => $user->name]);
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
