<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Notifications\NewMessageReceived;
use App\Services\UrlPreviewService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageThread extends Component
{
    use WithFileUploads;

    public string $transactionId;

    public string $organizationId;

    public string $organizationSlug;

    protected ?Transaction $transaction = null;

    public string $newMessage = '';

    public ?string $replyToMessageId = null;

    public ?array $replyingTo = null;

    public $photo = null;

    public function mount(Transaction $transaction): void
    {
        $this->transactionId = $transaction->id;
        $this->organizationId = $transaction->organization_id;
        $this->organizationSlug = currentOrganization()?->slug
            ?? Organization::findOrFail($transaction->organization_id)->slug;
        $this->markRead();
    }

    public function replyTo(string $messageId): void
    {
        $transaction = $this->transaction();

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
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

    public function updatedPhoto(): void
    {
        if (! $this->photo) {
            return;
        }
        $validator = validator(['photo' => $this->photo], ['photo' => 'image|max:10240']);
        if ($validator->fails()) {
            $this->photo = null;
            $this->addError('photo', __('messages.invalid_file'));
        }
    }

    public function sendMessage(): void
    {
        $this->validate([
            'newMessage' => 'required_without:photo|string|max:5000',
            'photo' => 'nullable|image|max:10240',
        ], [
            'newMessage.required_without' => __('messages.body_or_image_required'),
        ]);

        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        if (in_array($transaction->status, ['completed', 'refused', 'cancelled'])) {
            return;
        }

        $replyToId = $this->replyToMessageId;

        if ($replyToId !== null) {
            $parent = Message::where('id', $replyToId)
                ->where('transaction_id', $transaction->id)
                ->exists();

            if (! $parent) {
                $replyToId = null;
            }
        }

        $imagePath = null;

        if ($this->photo) {
            $imagePath = $this->storeImage($this->photo);
            $this->photo = null;
        }

        $url = UrlPreviewService::extractFirstUrl($this->newMessage);
        $preview = $url ? app(UrlPreviewService::class)->fetchPreview($url) : null;

        $msg = Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => $user->id,
            'reply_to_id' => $replyToId,
            'body' => $this->newMessage,
            'image_path' => $imagePath,
            'metadata' => $preview !== null ? ['url_preview' => $preview] : null,
            'type' => 'user',
            'organization_id' => $transaction->organization_id,
        ]);

        $this->newMessage = '';
        $this->cancelReply();
        $transaction->touch();
        $this->markRead();

        $recipient = $transaction->buyer_id === $user->id
            ? $transaction->seller
            : $transaction->buyer;

        $recipient->notify(new NewMessageReceived($transaction, $msg));
    }

    public function removePhoto(): void
    {
        $this->photo = null;
    }

    public function pinnedMessage(): ?Message
    {
        return $this->transaction()->messages()
            ->pinned()
            ->with('sender')
            ->first();
    }

    public function pinMessage(string $messageId): void
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
            ->first();

        if (! $message) {
            return;
        }

        Message::where('transaction_id', $transaction->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);

        $message->pin($user);
    }

    public function unpinMessage(): void
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        Message::where('transaction_id', $transaction->id)
            ->whereNotNull('pinned_at')
            ->update(['pinned_at' => null, 'pinned_by_id' => null]);
    }

    public function toggleReaction(string $messageId, string $reactionType): void
    {
        $user = auth()->user();
        $transaction = $this->transaction();

        if (! in_array($user->id, [$transaction->buyer_id, $transaction->seller_id])) {
            return;
        }

        if (! in_array($reactionType, Reaction::REACTION_TYPES, true)) {
            return;
        }

        $message = Message::where('id', $messageId)
            ->where('transaction_id', $transaction->id)
            ->first();

        if (! $message) {
            return;
        }

        $existing = Reaction::where('user_id', $user->id)
            ->where('reactionable_id', $message->id)
            ->where('reactionable_type', Message::class)
            ->first();

        if ($existing) {
            if ($existing->reaction_type === $reactionType) {
                $existing->delete();
            } else {
                $existing->update(['reaction_type' => $reactionType]);
            }
        } else {
            Reaction::create([
                'organization_id' => $message->organization_id,
                'user_id' => $user->id,
                'reactionable_id' => $message->id,
                'reactionable_type' => Message::class,
                'reaction_type' => $reactionType,
            ]);
        }
    }

    private function storeImage($file): string
    {
        $img = Image::decode($file);
        $img->scaleDown(1200, 800);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'message-images/'.$this->organizationId.'/messages/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 80)));

        return $relativePath;
    }

    public function markRead(): void
    {
        $userId = auth()->id();
        Message::where('transaction_id', $this->transaction()->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function render()
    {
        $transaction = $this->transaction(fresh: true);
        $this->markRead();

        $messages = $transaction->messages()
            ->with('sender')
            ->with('replyTo.sender')
            ->with('reactions')
            ->orderBy('created_at')
            ->get();

        $pinnedMessage = $this->pinnedMessage();
        $unreadCount = auth()->user()->unreadMessagesCount();

        $reactionData = [];
        $myReactions = [];
        $userId = auth()->id();

        foreach ($messages as $msg) {
            $counts = [];
            $myReaction = null;

            foreach ($msg->reactions as $reaction) {
                $type = $reaction->reaction_type;
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                if ($reaction->user_id === $userId) {
                    $myReaction = $type;
                }
            }

            $reactionData[$msg->id] = $counts;
            $myReactions[$msg->id] = $myReaction;
        }

        $organizationSlug = $this->organizationSlug;

        return view('livewire.message-thread', compact('transaction', 'messages', 'pinnedMessage', 'unreadCount', 'reactionData', 'myReactions', 'organizationSlug'));
    }

    private function transaction(bool $fresh = false): Transaction
    {
        $this->ensureOrganizationContext();

        if ($fresh || ! $this->transaction) {
            $this->transaction = Transaction::where('organization_id', $this->organizationId)
                ->findOrFail($this->transactionId);
        }

        return $this->transaction;
    }

    private function ensureOrganizationContext(): void
    {
        if (currentOrganization()?->id === $this->organizationId) {
            return;
        }

        $organization = Organization::findOrFail($this->organizationId);
        $this->organizationSlug = $organization->slug;
        app()->instance('current_organization', $organization);
    }
}
