@props([
    'autoScroll' => true,
    'wireKeyPrefix' => 'msg',
])

<div
    @if($autoScroll)
        x-data="{ atBottom: true }"
        x-init="$el.scrollTop = $el.scrollHeight; $watch('atBottom', value => { if(value) $nextTick(() => $el.scrollTop = $el.scrollHeight) })"
        x-on:scroll="atBottom = ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 10)"
    @endif
    {{ $attributes->merge(['class' => 'flex-1 overflow-y-auto min-h-0 px-4 py-4 space-y-1', 'style' => 'scrollbar-width: thin;']) }}
>
    @if(isset($messages))
        {{ $messages }}
    @endif

    @if(isset($empty) && (empty($messages) || ($messages instanceof \Illuminate\Support\Collection && $messages->isEmpty())))
        {{ $empty }}
    @endif

    @if(isset($after))
        {{ $after }}
    @endif
</div>
