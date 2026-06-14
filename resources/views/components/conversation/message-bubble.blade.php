@props([
    'type' => 'received',
    'time' => null,
    'avatar' => null,
    'name' => null,
    'class' => '',
    'replyTo' => null,
    'messageId' => null,
    'showReplyButton' => false,
    'imagePath' => null,
    'urlPreview' => null,
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
    <div class="max-w-[90%] sm:max-w-md md:max-w-lg {{ $bubbleClasses }} px-3 py-2">
        @if(!$isSent && ($avatar || $name))
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
        <div class="{{ $isSent ? 'bg-indigo-500/40' : 'bg-gray-200 dark:bg-gray-600' }} rounded px-2.5 py-1 mb-1.5 text-xs">
            <span class="font-medium">{{ $replyTo['sender_name'] ?? '' }}</span>
            <p class="truncate {{ $isSent ? 'text-indigo-100' : 'text-gray-500 dark:text-gray-400' }}">{{ $replyTo['body'] }}</p>
        </div>
        @endif

        @if($imagePath)
        <button
            x-on:click="$dispatch('open-image', { url: '{{ $imagePath }}' })"
            class="block mb-1.5 w-full max-w-[200px] rounded-lg overflow-hidden focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
            <img src="{{ $imagePath }}" alt="Image" class="w-full h-auto object-cover rounded-lg hover:opacity-90 transition">
        </button>
        @endif

        <div class="text-sm whitespace-pre-wrap">{{ $slot }}</div>

        @if($urlPreview)
            <x-conversation.url-preview-card :preview="$urlPreview" :is-sent="$isSent" />
        @endif

        @if($time || ($messageId && $showReplyButton))
        <div class="flex items-center gap-3 mt-1 {{ $isSent ? 'justify-end' : 'justify-start' }}">
            @if($time)
            <span class="text-[10px] {{ $timeClasses }}">{{ $time }}</span>
            @endif
            @if($messageId && $showReplyButton)
            <button
                x-on:click="$dispatch('reply-to-message', { messageId: '{{ $messageId }}' })"
                class="text-[11px] {{ $isSent ? 'text-indigo-200 hover:text-white' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300' }} transition"
            >
                Répondre
            </button>
            @endif
        </div>
        @endif
    </div>
</div>
