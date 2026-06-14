<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\Transaction;
use App\Notifications\NewMessageReceived;
use Livewire\Component;

class MessageThread extends Component
{
    public Transaction $transaction;

    public string $newMessage = '';

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    public function mount(Transaction $transaction): void
    {
        $this->transaction = $transaction;
        $this->markRead();
    }

    public function replyTo(string $messageId): void
    {
        $message = Message::where('id', $messageId)
            ->where('transaction_id', $this->transaction->id)
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

    public function sendMessage(): void
    {
        $this->validate(['newMessage' => 'required|string|max:5000']);

        $user = auth()->user();

        if (! in_array($user->id, [$this->transaction->buyer_id, $this->transaction->seller_id])) {
            return;
        }

        if (in_array($this->transaction->status, ['completed', 'refused', 'cancelled'])) {
            return;
        }

        $replyToId = $this->replyToMessageId;

        if ($replyToId !== null) {
            $parent = Message::where('id', $replyToId)
                ->where('transaction_id', $this->transaction->id)
                ->exists();

            if (! $parent) {
                $replyToId = null;
            }
        }

        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $user->id,
            'reply_to_id' => $replyToId,
            'body' => $this->newMessage,
            'type' => 'user',
            'organization_id' => $this->transaction->organization_id,
        ]);

        $this->newMessage = '';
        $this->cancelReply();
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
            ->with('replyTo.sender')
            ->orderBy('created_at')
            ->get();

        $unreadCount = auth()->user()->unreadMessagesCount();

        return view('livewire.message-thread', compact('messages', 'unreadCount'));
    }
}
