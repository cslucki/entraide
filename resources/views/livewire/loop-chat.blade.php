<div wire:poll.3s class="flex-1 flex flex-col min-h-0" x-on:reply-to-message.window="$wire.replyTo($event.detail.messageId)">
    <x-conversation.pinned-message-banner
        :pinned-message="$pinnedMessage"
        :can-unpin="$isMember"
    />

    <x-conversation.message-list :has-messages="$messages->isNotEmpty()">
        <x-slot:messages>
            @if($hasOlderMessages)
                <div class="flex justify-center pb-3">
                    <button
                        type="button"
                        x-on:click="$dispatch('loading-older')"
                        wire:click="loadOlderMessages"
                        class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 shadow-sm transition hover:border-violet-200 hover:text-violet-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:text-violet-300"
                    >
                        {{ __('messages.load_previous_messages') }}
                    </button>
                </div>
            @endif

            @forelse($messages as $msg)
                @php
                    $isOwn = $msg->sender_id === auth()->id();
                    $senderDisplayable = $msg->sender?->isDisplayableIn(currentOrganization()) ?? false;
                    $senderName = $msg->sender?->publicDisplayName() ?? 'BouclePro';
                    $replySenderName = $msg->replyTo?->sender?->publicDisplayName() ?? 'BouclePro';
                    $replyBody = $msg->replyTo?->isDeleted()
                        ? __('messages.deleted_message_placeholder')
                        : mb_substr((string) ($msg->replyTo?->body ?? ''), 0, 120);
                    $isDeleted = $msg->isDeleted();
                    $canEdit = $isMember && auth()->user() && $msg->isEditableBy(auth()->user());
                @endphp
                <div id="loop-message-{{ $msg->id }}" wire:key="msg-{{ $msg->id }}" class="transition-all duration-300">
                    @if($isDeleted)
                        <x-conversation.message-bubble
                            :type="$isOwn ? 'sent' : 'received'"
                            :time="$msg->created_at->diffForHumans()"
                            :name="$isOwn ? __('messages.me') : $senderName"
                            :message-id="$msg->id"
                            :reply-to="$msg->replyTo ? ['body' => $replyBody, 'sender_name' => $replySenderName] : null"
                        >
                            {{ __('messages.deleted_message_placeholder') }}
                        </x-conversation.message-bubble>
                    @elseif($editingMessageId === $msg->id)
                        <div class="flex justify-end">
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
                    @elseif($msg->type === 'help_request')
                        @php $meta = $msg->metadata ?? []; @endphp
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 rounded-full">{{ __('loops.help_request_badge') }}</span>
                                    <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                                    @if($msg->edited_at)
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ __('messages.edited') }}</span>
                                    @endif
                                </div>
                                @if($canDeleteMessages)
                                    <button
                                        wire:click="deleteMessage('{{ $msg->id }}')"
                                        wire:confirm="{{ __('messages.delete_confirm') }}"
                                        class="text-[11px] text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition"
                                    >
                                        {{ __('messages.delete') }}
                                    </button>
                                @endif
                            </div>
                            <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $meta['title'] ?? __('loops.help_request_badge') }}</h3>
                            <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{{ $msg->body }}</p>
                            @if(!empty($meta['expected_help_type']))
                                <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>{{ __('loops.expected_help', ['type' => $meta['expected_help_type']]) }}</span>
                                </div>
                            @endif
                            <div class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500 pt-1 border-t border-amber-200/50 dark:border-amber-700/30">
                                @if($msg->sender)
                                    <span>{{ $isOwn ? __('messages.me') : $senderName }}</span>
                                @else
                                    <span>{{ __('messages.member') }}</span>
                                @endif
                            </div>
                        </div>
                    @elseif($msg->type === 'ai')
                        <x-conversation.message-bubble
                            type="received"
                            :time="$msg->created_at->diffForHumans()"
                            :name="__('loops.ai_facilitator')"
                            :subtitle="isset($requestedByNames[$msg->id]) ? __('loops.ai_requested_by', ['name' => $requestedByNames[$msg->id]]) : null"
                            :message-id="$msg->id"
                            :show-reply-button="$isMember"
                            :show-pin-button="$isMember"
                            :show-delete-button="$canDeleteMessages"
                            :is-pinned="$pinnedMessage?->id === $msg->id"
                            :is-edited="$msg->edited_at !== null"
                            :show-reactions="$isMember"
                            :reaction-counts="$reactionData[$msg->id] ?? []"
                            :my-reaction="$myReactions[$msg->id] ?? null"
                            :reply-to="$msg->replyTo ? ['body' => $replyBody, 'sender_name' => $replySenderName] : null"
                            is-ai="true"
                        >
                            {!! $msg->body !!}
                        </x-conversation.message-bubble>
                    @else
                        <x-conversation.message-bubble
                            :type="$isOwn ? 'sent' : 'received'"
                            :time="$msg->created_at->diffForHumans()"
                            :name="$isOwn ? __('messages.me') : $senderName"
                            :avatar="$senderDisplayable ? $msg->sender?->avatar_url : null"
                            :message-id="$msg->id"
                            :show-reply-button="$isMember"
                            :show-pin-button="$isMember"
                            :show-edit-button="$canEdit"
                            :show-delete-button="$canDeleteMessages"
                            :is-pinned="$pinnedMessage?->id === $msg->id"
                            :is-edited="$msg->edited_at !== null"
                            :show-reactions="$isMember"
                            :reaction-counts="$reactionData[$msg->id] ?? []"
                            :my-reaction="$myReactions[$msg->id] ?? null"
                            :reply-to="$msg->replyTo ? ['body' => $replyBody, 'sender_name' => $replySenderName] : null"
                            :image-path="$msg->imageUrl()"
                            :url-preview="$msg->metadata['url_preview'] ?? null"
                        >
                            {!! $msg->body !!}
                        </x-conversation.message-bubble>
                    @endif
                </div>
            @empty
                <x-slot:empty>
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 dark:text-gray-500 py-12">
                        <svg class="w-12 h-12 mb-3 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <p class="text-sm">{{ __('loops.no_messages') }}</p>
                        <p class="text-xs mt-1">{{ __('loops.no_messages_hint') }}</p>
                    </div>
                </x-slot:empty>
            @endforelse
        </x-slot:messages>
    </x-conversation.message-list>

    @if($isMember && config('ai.chatloop.enabled', true))
        <div class="px-3 pt-2 flex flex-wrap items-center gap-x-4 gap-y-1" x-data="{ askOpen: false, asking: false, question: '' }">
            <form
                method="POST"
                action="{{ $aiRoute }}"
                x-data="{ submitting: false }"
                x-on:submit="submitting = true"
            >
                @csrf
                <input type="hidden" name="action" value="answer">
                <button
                    type="submit"
                    x-bind:disabled="submitting"
                    class="inline-flex items-center gap-2 text-xs font-medium text-violet-700 dark:text-violet-300 hover:text-violet-900 dark:hover:text-violet-100 transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <svg x-show="!submitting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/>
                    </svg>
                    <svg x-show="submitting" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-show="!submitting">{{ __('loops.ask_ai') }}</span>
                    <span x-show="submitting" x-cloak>{{ __('loops.ai_generating') }}</span>
                </button>
            </form>

            <button
                type="button"
                x-on:click="askOpen = true"
                class="inline-flex items-center gap-1.5 text-xs font-medium text-violet-700 dark:text-violet-300 hover:text-violet-900 dark:hover:text-violet-100 transition"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/>
                </svg>
                {{ __('loops.ask_question') }}
            </button>

            <template x-teleport="body">
                <div
                    x-show="askOpen"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    x-effect="document.body.style.overflow = askOpen ? 'hidden' : ''"
                    @keydown.escape.window="askOpen = false"
                >
                    <div x-show="askOpen" class="fixed inset-0 bg-black/50"></div>
                    <form
                        method="POST"
                        action="{{ $aiRoute }}"
                        x-show="askOpen"
                        x-on:submit="asking = true"
                        class="relative w-full max-w-lg mx-3 bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-5"
                    >
                        @csrf
                        <input type="hidden" name="action" value="ask">
                        <label for="ai-question" class="block text-sm font-medium text-gray-800 dark:text-gray-200">
                            {{ __('loops.ask_question') }}
                        </label>
                        <input
                            id="ai-question"
                            type="text"
                            name="question"
                            x-model="question"
                            required
                            maxlength="500"
                            placeholder="{{ __('loops.ask_question_placeholder') }}"
                            class="mt-2 w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:border-violet-500 focus:ring-violet-500"
                        >
                        <div class="mt-4 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                x-on:click="askOpen = false"
                                class="text-xs font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition"
                            >
                                {{ __('loops.cancel') }}
                            </button>
                            <button
                                type="submit"
                                x-bind:disabled="asking"
                                class="inline-flex items-center gap-2 text-xs font-medium text-white bg-violet-600 hover:bg-violet-700 rounded-lg px-3 py-2 transition disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span x-show="!asking">{{ __('loops.ask_question_submit') }}</span>
                                <span x-show="asking" x-cloak>{{ __('loops.ai_generating') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    @endif

    @if($isMember)
        <x-conversation.composer
            model="body"
            :placeholder="__('messages.write_message')"
            :replying-to="$replyingTo"
            on-cancel-reply="cancelReply"
            show-upload="true"
            :photo="$photo ?? null"
        >
            @error('body')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
            @error('photo')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </x-conversation.composer>
    @endif
</div>
