<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\Transaction;
use Livewire\Attributes\On;
use Livewire\Component;

class MessageThread extends Component
{
    public Transaction $transaction;
    public string $newMessage = '';

    public function mount(Transaction $transaction): void
    {
        $this->transaction = $transaction;
        $this->markRead();
    }

    public function sendMessage(): void
    {
        $this->validate(['newMessage' => 'required|string|max:5000']);

        $this->authorize('create', [Message::class, $this->transaction]);

        Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => auth()->id(),
            'body' => $this->newMessage,
            'type' => 'user',
        ]);

        $this->newMessage = '';
        $this->transaction->touch();
        $this->markRead();
    }

    public function markRead(): void
    {
        $userId = auth()->id();
        Message::where('transaction_id', $this->transaction->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render()
    {
        $this->transaction->refresh();
        $messages = $this->transaction->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->get();

        return view('livewire.message-thread', compact('messages'));
    }
}
