@props([
    'pinnedMessage' => null,
    'canUnpin' => false,
])

@if($pinnedMessage)
@php
    $senderName = $pinnedMessage->sender?->publicDisplayName() ?? 'BouclePro';
    $body = $pinnedMessage->isDeleted() ? __('messages.deleted_message_placeholder') : mb_substr($pinnedMessage->body, 0, 120);
@endphp
<div class="sticky top-0 z-20 bg-amber-50/95 dark:bg-amber-900/90 backdrop-blur border border-amber-200 dark:border-amber-700/50 rounded-lg px-3 py-2 mx-3 mt-2 flex items-center justify-between gap-3 shadow-sm">
    <div class="flex items-center gap-2 min-w-0">
        <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 4v12l4 2V4l-4 2zM8 4v12l-4 2V4l4 2z" />
        </svg>
        <div class="min-w-0">
            <p class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ __('messages.pinned_message') }}</p>
            <p class="text-xs text-gray-600 dark:text-gray-400 truncate">
                &laquo;&nbsp;{{ $body }}&nbsp;&raquo;&nbsp;&mdash;&nbsp;{{ $senderName }}
            </p>
        </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <button
            wire:click="showMessageInThread('{{ $pinnedMessage->id }}')"
            class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
        >
            {{ __('messages.view') }}
        </button>
        @if($canUnpin)
        <button
            wire:click="unpinMessage"
            class="text-xs text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition"
        >
            {{ __('messages.unpin') }}
        </button>
        @endif
    </div>
</div>
@endif
