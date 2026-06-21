<div wire:key="ai-agent-chat-{{ $targetUser->id }}">
    @if($profile)
    <x-conversation.shell class="border border-gray-200 dark:border-gray-700 mb-6">
        {{-- Header --}}
        <x-slot:header>
            <x-conversation.header
                :title="'Agent IA de ' . $targetUser->name"
                :subtitle="count($messages) > 0 ? '● En ligne' : null"
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
                            Écrire à
                        </a>
                        @endif
                    @else
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition shrink-0">
                            Écrire à
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
            <x-conversation.composer
                model="question"
                placeholder="Posez votre question..."
                :disabled="$isTyping"
                :loading="$isTyping"
                :error="$error"
                :rows="1"
            />
        </x-slot:composer>
    </x-conversation.shell>
    @endif
</div>
