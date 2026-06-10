<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\Transaction;
use App\Notifications\NewMessageReceived;
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

        $user = auth()->user();

        if (!in_array($user->id, [$this->transaction->buyer_id, $this->transaction->seller_id])) {
            return;
        }

        if (in_array($this->transaction->status, ['completed', 'refused', 'cancelled'])) {
            return;
        }

        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $user->id,
            'body' => $this->newMessage,
            'type' => 'user',
            'organization_id' => $this->transaction->organization_id,
        ]);

        $this->newMessage = '';
        $this->transaction->touch();
        $this->markRead();

        $recipient = $this->transaction->buyer_id === $user->id
            ? $this->transaction->seller
            : $this->transaction->buyer;

        $recipient->notify(new NewMessageReceived($this->transaction, $msg));
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
        $this->markRead();

        $messages = $this->transaction->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->get();

        $unreadCount = auth()->user()->unreadMessagesCount();

        return view('livewire.message-thread', compact('messages', 'unreadCount'));
    }
}
