@props([
    'model' => 'message',
    'placeholder' => 'Écrivez un message...',
    'disabled' => false,
    'loading' => false,
    'error' => null,
    'rows' => 1,
])

<div class="border-t border-gray-200 dark:border-gray-700 px-5 py-4">
    <form wire:submit="sendMessage" class="flex items-center gap-3">
        <textarea
            wire:model="{{ $model }}"
            rows="{{ $rows }}"
            @keydown.enter.prevent="if (!event.shiftKey) $wire.sendMessage()"
            class="flex-1 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
            placeholder="{{ $placeholder }}"
            @if($disabled || $loading) disabled @endif
        ></textarea>

        <button
            type="submit"
            class="flex-shrink-0 w-9 h-9 rounded-full bg-indigo-600 hover:bg-indigo-700 text-white flex items-center justify-center transition disabled:opacity-50"
            @if($disabled || $loading) disabled @endif
        >
            @if($loading)
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            @else
                <svg class="w-4 h-4 rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5m0 0l-7 7m7-7l7 7"/>
                </svg>
            @endif
        </button>
    </form>

    @if($error)
        <p class="text-xs text-red-500 mt-2">{{ $error }}</p>
    @endif

    @if($slot)
        {{ $slot }}
    @endif
</div>
