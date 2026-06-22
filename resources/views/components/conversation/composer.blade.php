@props([
    'model' => 'message',
    'placeholder' => null,
    'disabled' => false,
    'loading' => false,
    'error' => null,
    'rows' => 1,
    'replyingTo' => null,
    'onCancelReply' => null,
    'showUpload' => false,
    'photo' => null,
])

@php
    $placeholder ??= __('messages.write_message');
@endphp

<div class="border-t border-gray-200 dark:border-gray-700 px-5 py-4">
    @if($replyingTo)
    <div class="flex items-center justify-between mb-2 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-xs">
        <span class="text-gray-600 dark:text-gray-300 truncate">
            <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ __('messages.reply_to') }}</span>
            <span class="text-gray-500 dark:text-gray-400">{{ $replyingTo['sender_name'] ?? '' }} :</span>
            <span class="text-gray-400 dark:text-gray-500 truncate ml-1">{{ $replyingTo['body'] }}</span>
        </span>
        @if($onCancelReply)
        <button
            wire:click="{{ $onCancelReply }}"
            class="flex-shrink-0 ml-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        @endif
    </div>
    @endif

    @if($showUpload && $photo)
    <div class="flex items-center gap-2 mb-2 px-3 py-2 bg-gray-50 dark:bg-gray-900 rounded-lg">
        <img src="{{ $photo->temporaryUrl() }}" class="w-10 h-10 rounded object-cover flex-shrink-0" alt="{{ __('messages.image_preview') }}">
        <span class="text-xs text-gray-500 dark:text-gray-400 truncate flex-1">{{ $photo->getClientOriginalName() }}</span>
        <button
            wire:click="removePhoto"
            class="flex-shrink-0 text-gray-400 hover:text-red-500 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    @endif

    <form wire:submit="sendMessage" class="flex items-center gap-3">
        @if($showUpload)
        <label class="flex-shrink-0 w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 flex items-center justify-center cursor-pointer transition disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <input type="file" wire:model.live="photo" accept="image/*" class="hidden">
        </label>
        @endif

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
