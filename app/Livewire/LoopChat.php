<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Services\LoopMessageService;
use Livewire\Component;

class LoopChat extends Component
{
    public Loop $loop;

    public string $body = '';

    public bool $isMember = false;

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;

        $user = auth()->user();
        if ($user) {
            $this->isMember = LoopMember::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
        }
    }

    public function replyTo(string $messageId): void
    {
        $message = LoopMessage::where('id', $messageId)
            ->where('loop_id', $this->loop->id)
            ->with('sender')
            ->first();

        if (! $message) {
            return;
        }

        $this->replyToMessageId = $message->id;
        $this->replyingTo = [
            'body' => mb_substr($message->body, 0, 120),
            'sender_name' => $message->sender?->name ?? 'BouclePro',
        ];
    }

    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
        $this->replyingTo = null;
    }

    public function sendMessage(LoopMessageService $service): void
    {
        $this->validate(['body' => 'required|string|max:5000']);

        $user = auth()->user();
        if (! $user || ! $this->isMember) {
            return;
        }

        try {
            $service->sendUserMessage($this->loop, $user, $this->body, null, $this->replyToMessageId);
            $this->body = '';
            $this->cancelReply();
            $this->dispatch('message-sent');
        } catch (\RuntimeException) {
            $this->addError('body', 'Impossible d\'envoyer le message.');
        }
    }

    public function render()
    {
        $messages = $this->loop->messages()
            ->with('sender')
            ->with('replyTo.sender')
            ->oldest()
            ->get();

        return view('livewire.loop-chat', compact('messages'));
    }
}
