<div class="flex flex-col h-full" wire:poll.3000ms x-on:reply-to-message.window="$wire.replyTo($event.detail.messageId)">
    <!-- Status banner -->
    @php
        $isDirectConversation = $transaction->isDirectConversation();
        $_threadOrgSlug = $organizationSlug ?? request()->route('organization');
        $_threadRoute = function (string $routeName, string $organizationRouteName, array $params = []) use ($_threadOrgSlug) {
            return $_threadOrgSlug && Route::has($organizationRouteName)
                ? route($organizationRouteName, array_merge(['organization' => $_threadOrgSlug], $params))
                : route($routeName, $params);
        };

        /* TASK-1704 — ce qu'une bulle doit savoir d'une piece jointe.
           Les URL passent par `$_threadRoute` : la conversation vit sur deux
           surfaces (courte et prefixee), et une piece doit rester sur celle
           ou le membre se trouve. */
        $_threadAttachment = function ($message) use ($_threadRoute) {
            if (! $message->hasFile()) {
                return null;
            }

            $params = ['transaction' => $message->transaction_id, 'message' => $message->id];

            return [
                'name' => $message->fileDisplayName(),
                'size' => $message->fileSizeForHumans(),
                'mime' => $message->file_mime,
                'downloadUrl' => $_threadRoute('messages.attachments.download', 'organization.messages.attachments.download', $params),
                'previewUrl' => $message->fileIsPreviewable()
                    ? $_threadRoute('messages.attachments.preview', 'organization.messages.attachments.preview', $params)
                    : null,
            ];
        };
    @endphp

    {{-- TASK-1693 bug AC — pour une DISCUSSION, cette bande ne portait plus
         que le badge : les actions y sont masquees et il n'y a pas de points.
         Le badge ayant rejoint la ligne du pseudo, la bande entiere disparait
         — une ligne de gagnee sur un ecran de telephone. Pour un ECHANGE elle
         reste : elle y porte le statut, les points et les boutons. --}}
    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 {{ $isDirectConversation ? 'hidden' : '' }}"
         data-thread-status-banner>
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-2">
                @if($isDirectConversation)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-indigo-100 text-xs font-medium text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                        {{ __('messages.direct_conversation') }}
                    </span>
                @else
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ match($transaction->status) {
                            'pending' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                            'accepted' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                            'buyer_done' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
                            'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                            default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                        } }}">
                        {{ __('messages.status.' . $transaction->status) }}
                    </span>
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $transaction->points_agreed ?? $transaction->points_proposed }} pts
                    </span>
                @endif
            </div>

            <!-- Action buttons -->
            <div class="flex flex-wrap gap-2 {{ $isDirectConversation ? 'hidden' : '' }}">
                @php $user = auth()->user(); @endphp

                {{-- TASK-1693 bug S — « Accepter » s'affiche pour celui qui
                     n'a PAS propose. En dur sur `seller_id`, le bouton
                     revenait au proposant lui-meme des que l'echange naissait
                     d'une demande d'aide. --}}
                @if($transaction->status === 'pending' && $user->id === $transaction->decisionMakerId())
                    <form method="POST" action="{{ $_threadRoute('transactions.approve', 'organization.transactions.approve', ['transaction' => $transaction]) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700">{{ __('messages.actions.accept') }}</button>
                    </form>
                    <form method="POST" action="{{ $_threadRoute('transactions.refuse', 'organization.transactions.refuse', ['transaction' => $transaction]) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">{{ __('messages.actions.refuse') }}</button>
                    </form>
                @endif

                @if($transaction->status === 'pending' && in_array($user->id, [$transaction->buyer_id, $transaction->seller_id]))
                    <form method="POST" action="{{ $_threadRoute('transactions.adjust', 'organization.transactions.adjust', ['transaction' => $transaction]) }}" class="flex gap-1" x-data>
                        @csrf @method('PATCH')
                        <input type="number" name="points_proposed" min="1" value="{{ $transaction->points_proposed }}"
                            class="w-20 px-2 py-1 border rounded text-xs dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <button class="px-3 py-1 bg-yellow-500 text-white text-xs rounded hover:bg-yellow-600">{{ __('messages.actions.adjust') }}</button>
                    </form>
                @endif

                @if(in_array($transaction->status, ['pending', 'accepted']) && in_array($user->id, [$transaction->buyer_id, $transaction->seller_id]))
                    <form method="POST" action="{{ $_threadRoute('transactions.cancel', 'organization.transactions.cancel', ['transaction' => $transaction]) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-gray-500 text-white text-xs rounded hover:bg-gray-600">{{ __('messages.actions.cancel') }}</button>
                    </form>
                @endif

                @if($transaction->status === 'accepted' && $user->id === $transaction->buyer_id)
                    <form method="POST" action="{{ $_threadRoute('transactions.complete', 'organization.transactions.complete', ['transaction' => $transaction]) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">{{ __('messages.actions.mark_done') }}</button>
                    </form>
                @endif

                @if($transaction->status === 'buyer_done' && $user->id === $transaction->seller_id)
                    <form method="POST" action="{{ $_threadRoute('transactions.confirm', 'organization.transactions.confirm', ['transaction' => $transaction]) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700">{{ __('messages.actions.confirm') }}</button>
                    </form>
                    <form method="POST" action="{{ $_threadRoute('transactions.contest', 'organization.transactions.contest', ['transaction' => $transaction]) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-orange-500 text-white text-xs rounded hover:bg-orange-600">{{ __('messages.actions.contest') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <!-- Messages -->
    @php $canPin = in_array(auth()->id(), [$transaction->buyer_id, $transaction->seller_id]); @endphp

    <x-conversation.message-list>
        <x-slot:messages>
            <x-conversation.pinned-message-banner
                :pinned-message="$pinnedMessage"
                :can-unpin="$canPin"
            />

            @foreach($messages as $message)
                @php
                    $isPinned = $pinnedMessage?->id === $message->id;
                    $senderDisplayable = $message->sender?->isDisplayableIn(currentOrganization()) ?? false;
                    $senderName = $message->sender?->publicDisplayName() ?? __('messages.member');
                    $replySenderName = $message->replyTo?->sender?->publicDisplayName() ?? '';
                @endphp
                @if($message->isSystem())
                    <div class="flex justify-center">
                        <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs rounded-full">
                            {{ $message->body }}
                        </span>
                    </div>
                @elseif($editingMessageId === $message->id)
                    {{-- TASK-1704 correctif 2 — le formulaire de modification,
                         de la meme forme que celui du ChatLoop : il PREND LA
                         PLACE de la bulle, plutot que de s'ouvrir a cote. --}}
                    <div class="flex justify-end" data-message-edit-form>
                        <form wire:submit="saveEdit" class="w-full max-w-[90%] sm:max-w-md md:max-w-lg rounded-2xl rounded-br-sm bg-indigo-600 p-3 text-white shadow-sm">
                            <textarea
                                wire:model="editingBody"
                                rows="3"
                                class="w-full rounded-lg border-indigo-400 bg-white/95 text-sm text-gray-900 focus:border-white focus:ring-white"
                            ></textarea>
                            @error('editingBody')
                                <p class="mt-1 text-xs text-indigo-100">{{ $message }}</p>
                            @enderror
                            <div class="mt-2 flex justify-end gap-2">
                                <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-indigo-100 hover:text-white">
                                    {{ __('messages.cancel_edit') }}
                                </button>
                                <button type="submit" class="rounded-lg bg-white px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                                    {{ __('messages.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @elseif($message->sender_id === auth()->id())
                    @php $meta = $message->metadata ?? []; @endphp
                    <x-conversation.message-bubble
                        type="sent"
                        {{-- TASK-1704 correctif 2 — « il faudrait aussi pouvoir
                             modifier les messages textes, on peut le faire sur
                             la chatloop ». L'autorite reste au serveur : cette
                             prop ne fait qu'AFFICHER le bouton, et
                             `messageModifiable()` reevalue le droit au clic
                             comme a l'enregistrement. --}}
                        :show-edit-button="$message->isEditableBy(auth()->user())"
                        :time="$message->created_at->format('H:i')"
                        {{-- TASK-1693 bug Q — une correction par la moderation
                             doit se voir ici, exactement comme elle se voit
                             dans le ChatLoop. La bulle partagee le sait deja
                             faire ; seul ce fil ne le lui disait pas. --}}
                        :is-edited="$message->edited_at !== null"
                        :message-id="$message->id"
                        :show-reply-button="true"
                        :show-pin-button="$canPin"
                        :is-pinned="$isPinned"
                        :show-reactions="$canPin"
                        :reaction-counts="$reactionData[$message->id] ?? []"
                        :my-reaction="$myReactions[$message->id] ?? null"
                        :reply-to="$message->replyTo ? ['body' => mb_substr($message->replyTo->body, 0, 120), 'sender_name' => $replySenderName] : null"
                        :image-path="$message->imageUrl()"
                        :attachment="$_threadAttachment($message)"
                        :url-preview="$meta['url_preview'] ?? null"
                    >
                        {!! $message->body !!}
                    </x-conversation.message-bubble>
                @else
                    @php $meta = $message->metadata ?? []; @endphp
                    <x-conversation.message-bubble
                        type="received"
                        :time="$message->created_at->format('H:i')"
                        :name="$senderName"
                        :avatar="$senderDisplayable ? $message->sender?->avatar_url : null"
                        :is-edited="$message->edited_at !== null"
                        :message-id="$message->id"
                        :show-reply-button="true"
                        :show-pin-button="$canPin"
                        :is-pinned="$isPinned"
                        :show-reactions="$canPin"
                        :reaction-counts="$reactionData[$message->id] ?? []"
                        :my-reaction="$myReactions[$message->id] ?? null"
                        :reply-to="$message->replyTo ? ['body' => mb_substr($message->replyTo->body, 0, 120), 'sender_name' => $replySenderName] : null"
                        :image-path="$message->imageUrl()"
                        :attachment="$_threadAttachment($message)"
                        :url-preview="$meta['url_preview'] ?? null"
                    >
                        {!! $message->body !!}
                    </x-conversation.message-bubble>
                @endif
            @endforeach
        </x-slot:messages>

        <x-slot:after>
            <!-- Review form — visible after completion si pas encore noté -->
            @if($transaction->status === 'completed' && !$transaction->hasReviewFrom(auth()->id()))
            <div class="border-t-2 border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-4"
                 x-data="{ rating: 0, hovered: 0 }">
                <p class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 mb-3">
                    {{ __('messages.review_exchange') }}
                </p>
                <form method="POST" action="{{ route('reviews.store', $transaction) }}">
                    @csrf

                    <!-- Étoiles interactives -->
                    <div class="flex gap-1 mb-3">
                        @for($i = 1; $i <= 5; $i++)
                        <button type="button"
                            @click="rating = {{ $i }}"
                            @mouseenter="hovered = {{ $i }}"
                            @mouseleave="hovered = 0"
                            class="text-2xl transition-transform hover:scale-110 focus:outline-none">
                            <span x-text="(hovered || rating) >= {{ $i }} ? '★' : '☆'"
                                  :class="(hovered || rating) >= {{ $i }} ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600'"></span>
                        </button>
                        @endfor
                        <input type="hidden" name="rating" :value="rating">
                    </div>

                    <textarea name="comment" rows="2" placeholder="{{ __('messages.review_placeholder') }}"
                        class="w-full px-3 py-2 border border-indigo-200 dark:border-indigo-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 mb-3 resize-none"></textarea>

                    <button type="submit"
                        :disabled="rating === 0"
                        :class="rating === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-indigo-700'"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg font-medium transition">
                        {{ __('messages.send_review') }}
                    </button>
                </form>
            </div>
            @endif
        </x-slot:after>
    </x-conversation.message-list>

    <!-- Input -->
    @if(!in_array($transaction->status, ['completed', 'refused', 'cancelled']))
        <x-conversation.composer
            model="newMessage"
            :placeholder="__('messages.composer_placeholder')"
            :replying-to="$replyingTo"
            on-cancel-reply="cancelReply"
            show-upload="true"
            :photo="$photo ?? null"
            {{-- TASK-1704 — seule la Messagerie sait stocker autre chose
                 qu'une image : elle seule elargit le selecteur. --}}
            :upload-accept="\App\Support\Files\TypesDeFichiersAcceptes::pourAttributAccept()"
            :upload-label="__('messages.send_file')"
        >
            @error('newMessage')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
            @error('photo')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </x-conversation.composer>
    @else
    <div class="border-t border-gray-200 dark:border-gray-700 p-4 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('messages.conversation_closed') }}
    </div>
    @endif
</div>

<script>
    function syncUnreadBadge(count) {
        const link = document.querySelector('a[href="{{ $_threadRoute('messages.index', 'organization.messages.index') }}"]');
        if (!link) return;
        const existing = link.querySelector('.bg-red-500');
        if (count > 0) {
            const label = count > 9 ? '9+' : count;
            if (existing) {
                existing.textContent = label;
            } else {
                const badge = document.createElement('span');
                badge.className = 'absolute -top-2 -right-4 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center';
                badge.textContent = label;
                link.querySelector('.relative')?.appendChild(badge);
            }
        } else if (existing) {
            existing.remove();
        }
    }

    document.addEventListener('livewire:updated', () => {
        syncUnreadBadge({{ $unreadCount }});
    });
</script>
