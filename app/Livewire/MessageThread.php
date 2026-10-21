<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Notifications\NewMessageReceived;
use App\Services\UrlPreviewService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageThread extends Component
{
    use WithFileUploads;

    /**
     * TASK-1691 (P0) — ces trois proprietes decidaient du TENANT, et le client
     * les ecrivait.
     *
     * Mesure faite en T1690 : un membre de A, depuis un snapshot legitime de SA
     * conversation, envoyait `{"updates":{"organizationId":"<B>",
     * "transactionId":"<transaction de B>"}}`. `ensureOrganizationContext()`
     * re-liait alors `current_organization` sur B ; le scope tenant de
     * `Transaction` — qui TIENT par ailleurs — ne protegeait plus rien, et
     * `render()` servait le corps d'une conversation privee de B ainsi que le
     * nom de son interlocuteur. Pire, `render()` appelle `markRead()`, qui
     * ECRIT : `messages.read_at` d'un tenant etranger passait a `now()`.
     *
     * Le discriminant l'a prouve : en ne falsifiant QUE `transactionId`, sans
     * deplacer le tenant, la reponse etait **404**. La garde n'etait pas
     * absente — elle etait desarmee de l'interieur.
     */
    #[Locked]
    public string $transactionId;

    #[Locked]
    public string $organizationId;

    #[Locked]
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

        rescue(
            fn () => $recipient->notify(new NewMessageReceived($transaction, $msg)),
            fn (\Throwable $e) => Log::error('Email notification failed after successful business action', [
                'event' => 'message_created_notification',
                'message_id' => $msg->id,
                'organization_id' => $transaction->organization_id,
                'notifiable_id' => $recipient->id,
                'exception_class' => $e::class,
            ]),
            false,
        );
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
        // TASK-1623 — le CONTENU, jamais le chemin (cf. LoopChat::storeImage).
        $img = Image::decode($file->get());
        $img->scaleDown(1200, 800);

        $filename = Str::uuid()->toString().'.webp';
        $relativePath = 'message-images/'.$this->organizationId.'/messages/'.$filename;

        Storage::disk('public')->put($relativePath, (string) $img->encode(new WebpEncoder(quality: 80)));

        return $relativePath;
    }

    /**
     * TASK-1693 bug V — marquer lu, y compris les messages SYSTEME.
     *
     * `sender_id != $userId` les ecartait : ils portent `sender_id = NULL`, et
     * `NULL != 'x'` vaut NULL en SQL, donc jamais VRAI. Aucun message systeme
     * n'etait donc JAMAIS marque comme lu — toute la base de recette le
     * confirme.
     *
     * Tant qu'ils ne comptaient pas dans le badge, cela ne se voyait pas.
     * Depuis qu'ils y comptent (`User::unreadMessagesCount()`), un badge sans
     * ce correctif resterait allume DEFINITIVEMENT.
     */
    public function markRead(): void
    {
        $userId = auth()->id();
        Message::where('transaction_id', $this->transaction()->id)
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '!=', $userId))
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

    /**
     * TASK-1691 — la PREUVE DE PARTICIPATION passe ici, donc une seule fois.
     *
     * `sendMessage()`, `pinMessage()`, `unpinMessage()` et `toggleReaction()`
     * la portaient chacune de leur cote — et elles tenaient. Les deux methodes
     * qui ne la portaient PAS, `render()` et `markRead()`, sont justement
     * celles qui fuyaient et celle qui ecrivait. Une garde repetee quatre fois
     * est une garde qu'une cinquieme methode oubliera.
     *
     * `TransactionPolicy::view` exige deja `buyer_id` ou `seller_id` sur la
     * PAGE : aucun fil legitimement monte ne perd quoi que ce soit. Et le refus
     * est un **404** indistinguable d'une transaction inexistante — l'oracle
     * d'existence mesure en T1690 (200 pour un UUID reel, 404 pour un faux) se
     * referme, ce qui compte d'autant plus que `Transaction` emet des UUIDv7
     * dont les 48 premiers bits sont un horodatage.
     */
    private function transaction(bool $fresh = false): Transaction
    {
        $this->ensureOrganizationContext();

        if ($fresh || ! $this->transaction) {
            $userId = auth()->id();

            $this->transaction = Transaction::where('organization_id', $this->organizationId)
                ->where(fn ($query) => $query->where('buyer_id', $userId)->orWhere('seller_id', $userId))
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
