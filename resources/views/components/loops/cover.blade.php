{{-- Cover image with a premium deterministic gradient fallback (no external asset). --}}
@props(['loop', 'class' => 'aspect-[16/9]'])
@php
    $gradients = [
        'from-violet-500 to-indigo-600',
        'from-sky-500 to-blue-600',
        'from-emerald-500 to-teal-600',
        'from-amber-500 to-orange-600',
        'from-rose-500 to-pink-600',
        'from-fuchsia-500 to-purple-600',
    ];
    $gradient = $gradients[abs(crc32($loop->id)) % count($gradients)];
@endphp
<div {{ $attributes->merge(['class' => $class.' relative w-full overflow-hidden rounded-t-2xl bg-gray-100 dark:bg-gray-800']) }}>
    @if($loop->cover_image_url)
        <img src="{{ $loop->cover_image_url }}" alt="" class="h-full w-full object-cover">
    @else
        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br {{ $gradient }}">
            <svg class="h-10 w-10 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/>
            </svg>
        </div>
    @endif
</div>
