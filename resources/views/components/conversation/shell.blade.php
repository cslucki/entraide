<div {{ $attributes->merge(['class' => 'flex flex-col min-h-0 flex-1 bg-white dark:bg-gray-800 rounded-xl overflow-hidden']) }}>
    @if(isset($header))
        {{ $header }}
    @endif

    @if(isset($messages))
        {{ $messages }}
    @endif

    @if(isset($composer))
        {{ $composer }}
    @endif
</div>
