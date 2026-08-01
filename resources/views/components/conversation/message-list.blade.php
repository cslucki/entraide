@props([
    'autoScroll' => true,
    'hasMessages' => null,
    'wireKeyPrefix' => 'msg',
])

@php
    $shouldShowEmpty = $hasMessages === null
        ? (empty($messages) || ($messages instanceof \Illuminate\Support\Collection && $messages->isEmpty()))
        : ! $hasMessages;
@endphp

<div
    @if($autoScroll)
        x-data="{ atBottom: true, observer: null, preservingScroll: false, previousScrollHeight: 0 }"
        x-init="
            $el.scrollTop = $el.scrollHeight;
            observer = new MutationObserver(() => {
                if (atBottom) requestAnimationFrame(() => $el.scrollTop = $el.scrollHeight);
            });
            observer.observe($el, { childList: true, subtree: true });
        "
        x-on:scroll="atBottom = ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 10)"
        x-on:loading-older.window="previousScrollHeight = $el.scrollHeight; preservingScroll = true"
        x-on:older-messages-loaded.window="requestAnimationFrame(() => { if (preservingScroll) { $el.scrollTop = $el.scrollHeight - previousScrollHeight; preservingScroll = false } })"
        x-on:scroll-to-message.window="requestAnimationFrame(() => { const target = document.getElementById('loop-message-' + $event.detail.messageId); if (target) { target.scrollIntoView({ block: 'center' }); target.classList.add('ring-2', 'ring-violet-400', 'rounded-xl'); setTimeout(() => target.classList.remove('ring-2', 'ring-violet-400', 'rounded-xl'), 1800) } })"
    @endif
    {{ $attributes->merge(['class' => 'flex-1 overflow-y-auto min-h-0 px-4 py-4 space-y-1', 'style' => 'scrollbar-width: thin;']) }}
>
    @if(isset($messages))
        {{ $messages }}
    @endif

    @if(isset($empty) && $shouldShowEmpty)
        {{ $empty }}
    @endif

    @if(isset($after))
        {{ $after }}
    @endif
</div>
