<div wire:key="ai-agent-chat-{{ $targetUser->id }}" class="flex-1 flex flex-col min-h-0">
    @if($profile)
    <x-conversation.shell class="border border-gray-200 dark:border-gray-700">
        {{-- Header --}}
        <x-slot:header>
            <x-conversation.header
                :title="__('ai.ai_agent_of', ['name' => $targetUser->name])"
                :subtitle="count($messages) > 0 ? __('ai.available') : null"
                :status="count($messages) > 0 ? 'online' : null"
                class="bg-gray-50 dark:bg-gray-800/50"
                titleClass="text-sm"
                subtitleClass="text-xs text-green-600 dark:text-green-400"
            >
                <x-slot:icon>
                    <div class="w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                </x-slot:icon>

                <x-slot:actions>
                    @auth
                        @if(auth()->id() !== $targetUser->id)
                        <a href="{{ route('messages.with', $targetUser) }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition shrink-0">
                            {{ __('ai.write_directly_to', ['name' => $targetUser->name]) }}
                        </a>
                        @endif
                    @else
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition shrink-0">
                            {{ __('ai.login_to_write') }}
                        </a>
                    @endauth
                </x-slot:actions>
            </x-conversation.header>
        </x-slot:header>

        {{-- Messages --}}
        <x-slot:messages>
            <x-conversation.message-list
                :auto-scroll="false"
                x-data="{ container: null }"
                x-init="container = $el; $watch('container.scrollHeight', () => $nextTick(() => container.scrollTop = container.scrollHeight))"
                x-on:ai-agent-message.window="$nextTick(() => container.scrollTop = container.scrollHeight)"
            >
                <x-slot:messages>
                    @foreach($messages as $i => $msg)
                        <x-conversation.message-bubble
                            :type="$msg['role'] === 'user' ? 'sent' : 'received'"
                            :time="$msg['time']"
                            wire:key="msg-{{ $i }}"
                        >
                            {!! $msg['text'] !!}
                        </x-conversation.message-bubble>
                    @endforeach

                    @if($isTyping)
                        <div class="flex justify-start">
                            <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-bl-sm px-4 py-3">
                                <div class="flex items-center gap-1">
                                    <span class="w-2 h-2 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                                    <span class="w-2 h-2 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                                    <span class="w-2 h-2 bg-gray-400 dark:bg-gray-500 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                                </div>
                            </div>
                        </div>
                    @endif
                </x-slot:messages>
            </x-conversation.message-list>
        </x-slot:messages>

        {{-- Composer --}}
        <x-slot:composer>
            @if(!$maxTurnsReached)
                <div class="mb-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-3 py-2">
                    <p class="text-xs text-amber-700 dark:text-amber-400 flex items-start gap-1.5">
                        <svg class="w-3.5 h-3.5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"/>
                        </svg>
                        <span>{{ __('ai.visitor_chat_disclaimer') }}</span>
                    </p>
                </div>
            @else
                <div class="mb-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 px-3 py-2 text-center">
                    <p class="text-sm font-medium text-green-700 dark:text-green-400">
                        {{ __('ai.visitor_chat_max_turns_reached') }}
                    </p>
                </div>
            @endif

            <x-conversation.composer
                model="question"
                placeholder="{{ $maxTurnsReached ? __('ai.visitor_chat_composer_disabled') : __('ai.visitor_chat_placeholder') }}"
                :disabled="$isTyping || $maxTurnsReached"
                :loading="$isTyping"
                :error="$error"
                :rows="1"
            />
        </x-slot:composer>
    </x-conversation.shell>
    @endif
</div>
