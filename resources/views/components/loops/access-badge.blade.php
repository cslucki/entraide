{{--
    Access-mode badge. Two looks:
    - default : inline pill (presentation page, on a light surface)
    - overlay : frosted pill meant to sit on top of a cover image/gradient
--}}
@props(['loop', 'variant' => 'default'])
@php
    $inlineStyles = [
        'open' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300',
        'request' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300',
        'invitation' => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400',
    ];
    $overlayStyles = [
        'open' => 'text-emerald-700',
        'request' => 'text-amber-700',
        'invitation' => 'text-gray-700',
    ];
    $mode = $loop->access_mode ?: 'request';
    $style = $variant === 'overlay'
        ? 'border-white/70 bg-white/95 shadow-sm backdrop-blur-sm '.($overlayStyles[$mode] ?? $overlayStyles['request'])
        : ($inlineStyles[$mode] ?? $inlineStyles['request']);
@endphp
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] font-semibold $style"]) }}>
    {{ __('loops.access_badge_'.$mode) }}
</span>
