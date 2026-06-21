<div wire:poll.3s class="flex-1 flex flex-col min-h-0" x-on:reply-to-message.window="$wire.replyTo($event.detail.messageId)">
    <x-conversation.message-list :has-messages="$messages->isNotEmpty()">
        <x-slot:messages>
            <x-conversation.pinned-message-banner
                :pinned-message="$pinnedMessage"
                :can-unpin="$isMember"
            />

            @forelse($messages as $msg)
                @php $isOwn = $msg->sender_id === auth()->id(); @endphp
                <div wire:key="msg-{{ $msg->id }}">
                    @if($msg->type === 'help_request')
                        @php $meta = $msg->metadata ?? []; @endphp
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-2">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/40 px-2 py-0.5 rounded-full">Demande d'aide</span>
                                <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                            </div>
                            <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $meta['title'] ?? "Demande d'aide" }}</h3>
                            <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{{ $msg->body }}</p>
                            @if(!empty($meta['expected_help_type']))
                                <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <span>Aide attendue : {{ $meta['expected_help_type'] }}</span>
                                </div>
                            @endif
                            <div class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500 pt-1 border-t border-amber-200/50 dark:border-amber-700/30">
                                @if($msg->sender)
                                    <span>{{ $isOwn ? 'Moi' : $msg->sender->name }}</span>
                                @else
                                    <span>Membre</span>
                                @endif
                            </div>
                        </div>
                    @else
                        <x-conversation.message-bubble
                            :type="$isOwn ? 'sent' : 'received'"
                            :time="$msg->created_at->diffForHumans()"
                            :name="$isOwn ? 'Moi' : ($msg->sender?->name ?? 'BouclePro')"
                            :avatar="$msg->sender?->avatar_url"
                            :message-id="$msg->id"
                            :show-reply-button="$isMember"
                            :show-pin-button="$isMember"
                            :is-pinned="$pinnedMessage?->id === $msg->id"
                            :show-reactions="$isMember"
                            :reaction-counts="$reactionData[$msg->id] ?? []"
                            :my-reaction="$myReactions[$msg->id] ?? null"
                            :reply-to="$msg->replyTo ? ['body' => mb_substr($msg->replyTo->body, 0, 120), 'sender_name' => ($msg->replyTo->sender?->name ?? 'BouclePro')] : null"
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
                        <p class="text-sm">Aucun message pour le moment</p>
                        <p class="text-xs mt-1">Soyez le premier à écrire !</p>
                    </div>
                </x-slot:empty>
            @endforelse
        </x-slot:messages>
    </x-conversation.message-list>

    @if($isMember)
        <x-conversation.composer
            model="body"
            placeholder="Écrivez un message..."
            :replying-to="$replyingTo"
            on-cancel-reply="cancelReply"
            show-upload="true"
            :photo="$photo ?? null"
        >
            @error('body')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </x-conversation.composer>
    @endif
</div>
