<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
    <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ __('ai.setup_chat_title') }}
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('ai.setup_turns_left', ['count' => max(0, \App\Livewire\MemberAiProfileConversationalSetup::MAX_TURNS - $turnCount)]) }}
            </p>
        </div>
        <button wire:click="restart" class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 underline">
            {{ __('ai.setup_restart') }}
        </button>
    </div>

    <div class="p-4 sm:p-6 space-y-4 max-h-96 overflow-y-auto" wire:loading.class="opacity-50 pointer-events-none">
        @foreach($messages as $msg)
            <div class="flex {{ $msg['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%] rounded-2xl px-4 py-3 text-sm leading-relaxed {{ $msg['role'] === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200' }}">
                    {!! nl2br(e($msg['content'])) !!}
                </div>
            </div>
        @endforeach

        @if($isTyping)
            <div class="flex justify-start">
                <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                    <span class="inline-flex gap-1">
                        <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:0ms"></span>
                        <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:150ms"></span>
                        <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay:300ms"></span>
                    </span>
                </div>
            </div>
        @endif
    </div>

    <div class="p-4 sm:p-6 border-t border-gray-200 dark:border-gray-700">
        @if($turnCount < \App\Livewire\MemberAiProfileConversationalSetup::MAX_TURNS)
            <form wire:submit="send">
                <div class="flex gap-3">
                    <input wire:model="currentInput"
                           type="text"
                           autocomplete="off"
                           placeholder="{{ __('ai.setup_input_placeholder') }}"
                           class="flex-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                           @if($isTyping) disabled @endif>
                    <button type="submit"
                            @if($isTyping) disabled @endif
                            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition disabled:opacity-50">
                        {{ __('ai.setup_send') }}
                    </button>
                </div>
            </form>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center">
                {{ __('ai.setup_max_turns_reached') }}
            </p>
        @endif
    </div>
</div>
