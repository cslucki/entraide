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
    'showPinButton' => false,
    'isPinned' => false,
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
    : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-2xl rounded-bl-sm';

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

        @if($time || ($messageId && ($showReplyButton || $showPinButton || $showReactions)))
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
            @if($messageId && $showPinButton)
                @if($isPinned)
                <button
                    wire:click="unpinMessage"
                    class="text-[11px] {{ $isSent ? 'text-amber-200 hover:text-white' : 'text-amber-500 hover:text-amber-700 dark:hover:text-amber-300' }} transition"
                    title="Désépingler"
                >
                    <svg class="w-3.5 h-3.5 inline-block fill-current" viewBox="0 0 24 24">
                        <path d="M16 12V4l4-2v2l-4 2v6l-2 2H8l-2-2V8l-4 2v2l4 4v4h6v-4l4-4z"/>
                    </svg>
                </button>
                @else
                <button
                    wire:click="pinMessage('{{ $messageId }}')"
                    class="text-[11px] {{ $isSent ? 'text-indigo-200 hover:text-white' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300' }} transition"
                    title="Épingler"
                >
                    <svg class="w-3.5 h-3.5 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 4v12l4 2V4l-4 2zM8 4v12l-4 2V4l4 2z"/>
                    </svg>
                </button>
                @endif
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
            aria-label="Réagir au message"
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
                    aria-label="Réagir avec {{ $type }}"
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
                aria-label="Afficher plus de réactions"
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
                    aria-label="Réagir avec {{ $type }}"
                >
                    {{ $emoji }}
                </button>
                @endif
            @endforeach
        </div>
        @endif
    </div>
</div>
