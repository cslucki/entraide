<div wire:poll.3s class="flex-1 flex flex-col min-h-0">
    <div x-data="{
            atBottom: true,
            observer: null,
            init() {
                this.$nextTick(() => { this.$el.scrollTop = this.$el.scrollHeight; this.atBottom = true; });
                this.observer = new MutationObserver(() => {
                    if (this.atBottom) requestAnimationFrame(() => this.$el.scrollTop = this.$el.scrollHeight);
                });
                this.observer.observe(this.$el, { childList: true });
            },
            destroy() {
                this.observer?.disconnect();
            },
        }"
         x-on:scroll="atBottom = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 60"
         x-on:message-sent.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
         class="flex-1 overflow-y-auto min-h-0 px-4 py-4 space-y-1"
         style="max-height: inherit; height: 100%;">
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
                    <div class="flex gap-3 py-1.5 {{ $isOwn ? 'flex-row-reverse' : '' }}">
                        @if($msg->sender)
                            <img src="{{ $msg->sender->avatar_url }}" alt=""
                                 class="w-7 h-7 rounded-full flex-shrink-0 mt-0.5">
                        @else
                            <div class="w-7 h-7 rounded-full flex-shrink-0 mt-0.5 bg-gray-200 dark:bg-gray-600 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        @endif
                        <div class="max-w-[85%] md:max-w-[75%] min-w-0">
                            <div class="flex items-baseline gap-2 mb-0.5 {{ $isOwn ? 'justify-end' : '' }}">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isOwn ? 'Moi' : ($msg->sender?->name ?? 'BouclePro') }}</span>
                                <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                            </div>
                            <div class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed {{ $isOwn ? 'bg-indigo-600 text-white rounded-br-sm' : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-bl-sm' }}">
                                {{ $msg->body }}
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <svg class="w-10 h-10 text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <p class="text-sm text-gray-400 dark:text-gray-500">Aucun message pour le moment.</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Écrivez le premier message de cette boucle.</p>
            </div>
        @endforelse
    </div>

    @if($isMember)
        <div class="px-4 py-3 flex-shrink-0 border-t border-gray-200 dark:border-gray-700">
            <form wire:submit="sendMessage" class="flex items-center gap-3">
                <div class="flex-1 min-w-0">
                    <label for="livewire-body" class="sr-only">Votre message</label>
                    <textarea wire:model="body" id="livewire-body" rows="1"
                              placeholder="Écrivez un message..."
                              class="block w-full resize-none px-4 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-full bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-transparent overflow-hidden"
                              @keydown.enter.prevent="if(!$event.shiftKey){ $event.target.closest('form').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})) }"></textarea>
                    @error('body') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="submit"
                        class="flex-shrink-0 w-10 h-10 flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white rounded-full transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
    @endif
</div>
