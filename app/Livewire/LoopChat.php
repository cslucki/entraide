<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Services\LoopMessageService;
use Livewire\Component;

class LoopChat extends Component
{
    public Loop $loop;

    public string $body = '';

    public bool $isMember = false;

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

    public function sendMessage(LoopMessageService $service): void
    {
        $this->validate(['body' => 'required|string|max:5000']);

        $user = auth()->user();
        if (! $user || ! $this->isMember) {
            return;
        }

        try {
            $service->sendUserMessage($this->loop, $user, $this->body);
            $this->body = '';
        } catch (\RuntimeException) {
            $this->addError('body', 'Impossible d\'envoyer le message.');
        }
    }

    public function render()
    {
        $messages = $this->loop->messages()
            ->with('sender')
            ->oldest()
            ->get();

        return view('livewire.loop-chat', compact('messages'));
    }
}
