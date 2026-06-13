<div wire:key="ai-agent-chat-{{ $targetUser->id }}">
    @if($profile)
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 mb-6 overflow-hidden">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
            <div class="relative">
                <div class="w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800"></span>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">Agent IA de {{ $targetUser->name }}</p>
                @if(count($messages) > 0)
                <p class="text-xs text-green-600 dark:text-green-400">&nbsp;● En ligne</p>
                @endif
            </div>
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
        </div>

        <div x-data="{ container: null }"
             x-init="container = $el; $watch('container.scrollHeight', () => $nextTick(() => container.scrollTop = container.scrollHeight))"
             x-on:ai-agent-message.window="$nextTick(() => container.scrollTop = container.scrollHeight)"
             class="px-5 py-4 space-y-4 max-h-96 overflow-y-auto scroll-smooth"
             style="scrollbar-width: thin;">

            @foreach($messages as $i => $msg)
                @if($msg['role'] === 'user')
                <div class="flex justify-end" wire:key="msg-{{ $i }}">
                    <div class="max-w-[80%] bg-indigo-600 text-white rounded-2xl rounded-br-sm px-4 py-2.5">
                        <div class="text-sm whitespace-pre-wrap">{{ $msg['text'] }}</div>
                        <p class="text-[10px] text-indigo-200 mt-1 text-right">{{ $msg['time'] }}</p>
                    </div>
                </div>
                @else
                <div class="flex justify-start" wire:key="msg-{{ $i }}">
                    <div class="max-w-[80%] bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm px-4 py-2.5">
                        <div class="text-sm whitespace-pre-wrap">{{ $msg['text'] }}</div>
                        <p class="text-[10px] text-gray-400 mt-1">{{ $msg['time'] }}</p>
                    </div>
                </div>
                @endif
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
        </div>

        <div class="border-t border-gray-200 dark:border-gray-700 px-5 py-4">
            <form wire:submit="sendMessage" class="flex items-center gap-3">
                <input wire:model="question" type="text"
                    class="flex-1 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition"
                    placeholder="Posez votre question..."
                    @if($isTyping) disabled @endif>
                <button type="submit"
                    class="flex-shrink-0 w-9 h-9 rounded-full bg-indigo-600 hover:bg-indigo-700 text-white flex items-center justify-center transition disabled:opacity-50"
                    @if($isTyping) disabled @endif>
                    <svg class="w-4 h-4 rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5m0 0l-7 7m7-7l7 7"/>
                    </svg>
                </button>
            </form>
            @if($error)
            <p class="text-xs text-red-500 mt-2">{{ $error }}</p>
            @endif
        </div>
    </div>
    @endif
</div>
