@props([
    'type' => 'received',
    'time' => null,
    'avatar' => null,
    'name' => null,
    'class' => '',
    'replyTo' => null,
    'messageId' => null,
    'showReplyButton' => false,
])

@php
$isSent = $type === 'sent';
$containerClasses = $isSent
    ? 'flex justify-end'
    : 'flex justify-start';

$bubbleClasses = $isSent
    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm'
    : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm';

$timeClasses = $isSent
    ? 'text-indigo-200'
    : 'text-gray-400';
@endphp

<div {{ $attributes->merge(['class' => $containerClasses . ' ' . $class]) }}>
    <div class="max-w-[85%] md:max-w-[75%] {{ $bubbleClasses }} px-4 py-2.5">
        @if($avatar || $name)
        <div class="flex items-center gap-2 mb-1">
            @if($avatar)
            <img src="{{ $avatar }}" alt="" class="w-5 h-5 rounded-full">
            @endif
            @if($name)
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $name }}</span>
            @endif
        </div>
        @endif

        @if($replyTo)
        <div class="{{ $isSent ? 'bg-indigo-500/40' : 'bg-gray-200 dark:bg-gray-600' }} rounded-lg px-3 py-1.5 mb-2 text-xs leading-relaxed">
            <span class="font-medium">{{ $replyTo['sender_name'] ?? '' }}</span>
            <p class="truncate {{ $isSent ? 'text-indigo-100' : 'text-gray-500 dark:text-gray-400' }}">{{ $replyTo['body'] }}</p>
        </div>
        @endif

        <div class="text-sm whitespace-pre-wrap">{{ $slot }}</div>

        @if($time)
        <p class="text-[10px] {{ $timeClasses }} mt-1 {{ $isSent ? 'text-right' : '' }}">{{ $time }}</p>
        @endif

        @if($messageId && $showReplyButton)
        <div class="mt-1 {{ $isSent ? 'text-right' : '' }}">
            <button
                x-on:click="$dispatch('reply-to-message', { messageId: '{{ $messageId }}' })"
                class="text-[11px] {{ $isSent ? 'text-indigo-200 hover:text-white' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300' }} transition"
            >
                Répondre
            </button>
        </div>
        @endif
    </div>
</div>
