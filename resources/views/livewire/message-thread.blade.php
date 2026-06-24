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
    @endphp

    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
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

                @if($transaction->status === 'pending' && $user->id === $transaction->seller_id)
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
                @php $isPinned = $pinnedMessage?->id === $message->id; @endphp
                @if($message->isSystem())
                    <div class="flex justify-center">
                        <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs rounded-full">
                            {{ $message->body }}
                        </span>
                    </div>
                @elseif($message->sender_id === auth()->id())
                    @php $meta = $message->metadata ?? []; @endphp
                    <x-conversation.message-bubble
                        type="sent"
                        :time="$message->created_at->format('H:i')"
                        :message-id="$message->id"
                        :show-reply-button="true"
                        :show-pin-button="$canPin"
                        :is-pinned="$isPinned"
                        :show-reactions="$canPin"
                        :reaction-counts="$reactionData[$message->id] ?? []"
                        :my-reaction="$myReactions[$message->id] ?? null"
                        :reply-to="$message->replyTo ? ['body' => mb_substr($message->replyTo->body, 0, 120), 'sender_name' => ($message->replyTo->sender?->name ?? '')] : null"
                        :image-path="$message->imageUrl()"
                        :url-preview="$meta['url_preview'] ?? null"
                    >
                        {!! $message->body !!}
                    </x-conversation.message-bubble>
                @else
                    @php $meta = $message->metadata ?? []; @endphp
                    <x-conversation.message-bubble
                        type="received"
                        :time="$message->created_at->format('H:i')"
                        :avatar="$message->sender?->avatar_url"
                        :message-id="$message->id"
                        :show-reply-button="true"
                        :show-pin-button="$canPin"
                        :is-pinned="$isPinned"
                        :show-reactions="$canPin"
                        :reaction-counts="$reactionData[$message->id] ?? []"
                        :my-reaction="$myReactions[$message->id] ?? null"
                        :reply-to="$message->replyTo ? ['body' => mb_substr($message->replyTo->body, 0, 120), 'sender_name' => ($message->replyTo->sender?->name ?? '')] : null"
                        :image-path="$message->imageUrl()"
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
