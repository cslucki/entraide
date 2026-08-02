@props(['loop'])
@php
    $styles = [
        'open' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300',
        'request' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300',
        'invitation' => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400',
    ];
    $style = $styles[$loop->access_mode] ?? $styles['request'];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide $style"]) }}>
    {{ __('loops.access_mode_'.$loop->access_mode) }}
</span>
