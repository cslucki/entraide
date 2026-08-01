@props([
    'type' => 'received',
    'time' => null,
    'avatar' => null,
    'name' => null,
    'subtitle' => null,
    'isAi' => false,
    'class' => '',
    'replyTo' => null,
    'messageId' => null,
    'showReplyButton' => false,
    'imagePath' => null,
    'urlPreview' => null,
    'showPinButton' => false,
    'showEditButton' => false,
    'showDeleteButton' => false,
    'isPinned' => false,
    'isEdited' => false,
    'showReactions' => false,
    'reactionCounts' => [],
    'myReaction' => null,
])

@php
$isSent = $type === 'sent';
$containerClasses = $isSent
    ? 'flex justify-end'
    : 'flex justify-start';

$bubbleClasses = $isSent
    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm'
    : ($isAi
        ? 'bg-violet-50 dark:bg-violet-900 ring-1 ring-violet-200 dark:ring-violet-800 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm'
        : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm');

$nameClasses = $isSent
    ? 'text-xs font-medium text-indigo-200'
    : ($isAi
        ? 'text-xs font-medium text-violet-600 dark:text-violet-300'
        : 'text-xs font-medium text-gray-500 dark:text-gray-400');

$timeClasses = $isSent
    ? 'text-indigo-200'
    : 'text-gray-400';

$reactionTypes = App\Models\Reaction::REACTION_TYPES;
$primaryReactionTypes = ['thumbs_up', 'heart', 'thanks', 'surprised', 'sad'];
$secondaryReactionTypes = array_values(array_diff($reactionTypes, $primaryReactionTypes));
$reactionEmojis = App\Models\Reaction::emojiMap();
$visibleReactionCounts = array_filter($reactionCounts, fn ($count) => $count > 0);
@endphp

<div
    {{ $attributes->merge(['class' => $containerClasses . ' group ' . $class]) }}
    @if($showReactions && $messageId)
    x-data="{ id: @js((string) $messageId), open: false, hover: false, showMore: false, pressTimer: null }"
    x-on:mouseover="hover = true"
    x-on:mouseleave="hover = false"
    x-on:reaction-menu-opened.window="if ($event.detail.id !== id) { open = false; showMore = false }"
    x-on:keydown.escape.window="open = false; showMore = false"
    x-on:click.outside="open = false; showMore = false"
    @endif
>
    <div class="relative">
    <div
        class="max-w-[90%] sm:max-w-md md:max-w-lg {{ $bubbleClasses }} px-3 py-2"
        @if($showReactions && $messageId)
        x-on:touchstart.passive="if (!$event.target.closest('button,a,input,textarea,select')) { pressTimer = setTimeout(() => { $dispatch('reaction-menu-opened', { id }); open = true }, 450) }"
        x-on:touchend="clearTimeout(pressTimer)"
        x-on:touchmove="clearTimeout(pressTimer)"
        x-on:touchcancel="clearTimeout(pressTimer)"
        x-on:contextmenu.prevent="$dispatch('reaction-menu-opened', { id }); open = true"
        @endif
    >
        @if(!$isSent && ($avatar || $name || $subtitle))
        <div class="flex items-center gap-2 mb-1">
            @if($avatar)
            <img src="{{ $avatar }}" alt="" class="w-5 h-5 rounded-full">
            @endif
            @if($name)
            <span class="{{ $nameClasses }}">{{ $name }}</span>
            @endif
            @if($subtitle)
            <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $subtitle }}</span>
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
            <img src="{{ $imagePath }}" alt="{{ __('messages.image_alt') }}" class="w-full h-auto object-cover rounded-lg hover:opacity-90 transition">
        </button>
        @endif

        <div class="cursor-default whitespace-pre-wrap text-sm" style="caret-color: transparent;">{!! markdown((string) $slot) !!}</div>

        @if($urlPreview)
            <x-conversation.url-preview-card :preview="$urlPreview" :is-sent="$isSent" />
        @endif

        @if($time || $isEdited || ($messageId && ($showReplyButton || $showPinButton || $showEditButton || $showDeleteButton || $showReactions)))
        <div class="mt-1 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                @if($time)
                <span class="text-[10px] {{ $timeClasses }}">{{ $time }}</span>
                @endif
                @if($isEdited)
                <span class="text-[10px] {{ $timeClasses }}">{{ __('messages.edited') }}</span>
                @endif
            </div>

            @if($messageId && ($showReplyButton || $showPinButton || $showEditButton || $showDeleteButton))
            <div class="ml-auto flex items-center gap-1">
                @if($showReplyButton)
                <button
                    x-on:click="$dispatch('reply-to-message', { messageId: '{{ $messageId }}' })"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-indigo-600 dark:hover:bg-gray-800 dark:hover:text-indigo-300' }}"
                    title="{{ __('messages.reply') }}"
                    aria-label="{{ __('messages.reply') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.reply') }}</span>
                </button>
                @endif
                @if($showPinButton)
                    @if($isPinned)
                    <button
                        wire:click="unpinMessage"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-amber-500 transition {{ $isSent ? 'hover:bg-white/10 hover:text-white' : 'hover:bg-amber-50 hover:text-amber-700 dark:hover:bg-amber-900/30 dark:hover:text-amber-300' }}"
                        title="{{ __('messages.unpin') }}"
                        aria-label="{{ __('messages.unpin') }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M8 6.5V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v7l2 3H8.5M12 18v3"/>
                        </svg>
                        <span class="sr-only">{{ __('messages.unpin') }}</span>
                    </button>
                    @else
                    <button
                        wire:click="pinMessage('{{ $messageId }}')"
                        class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-amber-600 dark:hover:bg-gray-800 dark:hover:text-amber-300' }}"
                        title="{{ __('messages.pin') }}"
                        aria-label="{{ __('messages.pin') }}"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 4.5 19.5 8.25m-3.75-3.75L9 11.25 8.25 15 12 14.25 18.75 7.5M8.25 15 4.5 19.5"/>
                        </svg>
                        <span class="sr-only">{{ __('messages.pin') }}</span>
                    </button>
                    @endif
                @endif
                @if($showEditButton)
                <button
                    wire:click="editMessage('{{ $messageId }}')"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-white/70 hover:text-indigo-600 dark:hover:bg-gray-800 dark:hover:text-indigo-300' }}"
                    title="{{ __('messages.edit') }}"
                    aria-label="{{ __('messages.edit') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.edit') }}</span>
                </button>
                @endif
                @if($showDeleteButton)
                <button
                    wire:click="deleteMessage('{{ $messageId }}')"
                    wire:confirm="{{ __('messages.delete_confirm') }}"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full transition {{ $isSent ? 'text-indigo-200 hover:bg-white/10 hover:text-white' : 'text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400' }}"
                    title="{{ __('messages.delete') }}"
                    aria-label="{{ __('messages.delete') }}"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79 18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                    </svg>
                    <span class="sr-only">{{ __('messages.delete') }}</span>
                </button>
                @endif
            </div>
            @endif
        </div>
        @endif

        @if(!empty($visibleReactionCounts))
        <div class="flex items-center gap-1 mt-1 flex-wrap {{ $isSent ? 'justify-end' : 'justify-start' }}">
            @foreach($reactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji && !empty($visibleReactionCounts[$type]))
                <button
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    class="inline-flex items-center gap-0.5 text-[11px] leading-none px-1.5 py-0.5 rounded-full transition
                        {{ $myReaction === $type
                            ? ($isSent ? 'bg-indigo-500/30 ring-1 ring-indigo-300/50' : 'bg-indigo-100 dark:bg-indigo-900/40 ring-1 ring-indigo-300 dark:ring-indigo-600')
                            : ($isSent ? 'bg-indigo-500/20 hover:bg-indigo-500/30' : 'bg-white/70 hover:bg-white dark:bg-gray-800/60 dark:hover:bg-gray-800') }}"
                    title="{{ $type }}"
                >
                    <span class="text-xs leading-none">{{ $emoji }}</span>
                    <span class="{{ $isSent ? 'text-indigo-200' : 'text-gray-500 dark:text-gray-400' }}">{{ $visibleReactionCounts[$type] }}</span>
                </button>
                @endif
            @endforeach
        </div>
        @endif
    </div>

        @if($showReactions && $messageId)
        <button
            type="button"
            x-on:click.stop="if (open) { open = false; showMore = false } else { $dispatch('reaction-menu-opened', { id }); open = true }"
            x-bind:style="'top: 50%; transform: translateY(-50%); {{ $isSent ? 'left: -1.75rem;' : 'right: -1.75rem;' }} ' + ((hover || open) ? 'opacity: 1;' : 'opacity: 0; pointer-events: none;')"
            x-bind:tabindex="(hover || open) ? 0 : -1"
            x-on:focus="hover = true"
            x-on:blur="if (!open) hover = false"
            class="inline-flex absolute h-6 w-6 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm ring-1 ring-gray-200 transition hover:text-indigo-600 hover:ring-indigo-200 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:text-indigo-300"
            aria-label="{{ __('messages.react_to_message') }}"
        >
            <span class="text-lg leading-none">☺</span>
        </button>

        <div
            x-show="open"
            x-cloak
            style="top: 50%; {{ $isSent ? 'left: -1rem; transform: translate(-50%, calc(-100% - 1rem));' : 'right: -1rem; transform: translate(50%, calc(-100% - 1rem));' }}"
            class="absolute z-30 flex items-center gap-1 rounded-full border border-gray-200 bg-white px-2 py-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-800"
        >
            @foreach($primaryReactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji)
                <button
                    type="button"
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    x-on:click="open = false"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm transition hover:scale-110 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:hover:bg-gray-700 {{ $myReaction === $type ? 'bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-900/40 dark:ring-indigo-600' : '' }}"
                    title="{{ $type }}"
                    aria-label="{{ __('messages.react_with', ['type' => $type]) }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
            @if(!empty($secondaryReactionTypes))
            <button
                type="button"
                x-on:click.stop="showMore = !showMore"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full text-base text-gray-900 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:text-white dark:hover:bg-gray-700"
                aria-label="{{ __('messages.more_reactions') }}"
            >
                ›
            </button>
            @endif
            @foreach($secondaryReactionTypes as $type)
                @php $emoji = $reactionEmojis[$type] ?? null; @endphp
                @if($emoji)
                <button
                    x-show="showMore"
                    type="button"
                    wire:click="toggleReaction('{{ $messageId }}', '{{ $type }}')"
                    x-on:click="open = false; showMore = false"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-full text-sm transition hover:scale-110 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-400 dark:hover:bg-gray-700 {{ $myReaction === $type ? 'bg-indigo-100 ring-1 ring-indigo-300 dark:bg-indigo-900/40 dark:ring-indigo-600' : '' }}"
                    title="{{ $type }}"
                    aria-label="{{ __('messages.react_with', ['type' => $type]) }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
        </div>
        @endif
    </div>
</div>
