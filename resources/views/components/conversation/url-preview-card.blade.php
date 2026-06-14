@props([
    'preview' => [],
    'isSent' => false,
])

@php
    $domain = $preview['domain'] ?? '';
    $title = $preview['title'] ?? '';
    $description = $preview['description'] ?? '';
    $image = $preview['image'] ?? null;
    $url = $preview['url'] ?? '';
    $bgClasses = $isSent ? 'bg-indigo-500/20 hover:bg-indigo-500/30' : 'bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500';
    $borderClasses = $isSent ? 'border-indigo-400/30' : 'border-gray-300 dark:border-gray-500';
@endphp

<a
    href="{{ $url }}"
    target="_blank"
    rel="noopener noreferrer"
    class="block mt-1.5 rounded-lg {{ $bgClasses }} {{ $borderClasses }} border transition overflow-hidden"
>
    <div class="flex items-start gap-2 p-2">
        @if($image)
            <img
                src="{{ $image }}"
                alt=""
                class="w-12 h-12 rounded flex-shrink-0 object-cover mt-0.5"
                loading="lazy"
            >
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-[10px] uppercase tracking-wider {{ $isSent ? 'text-indigo-200' : 'text-gray-400' }} truncate">
                {{ $domain }}
            </p>
            @if($title)
                <p class="text-xs font-semibold leading-snug line-clamp-2 {{ $isSent ? 'text-white' : 'text-gray-900 dark:text-gray-100' }}">
                    {{ $title }}
                </p>
            @endif
            @if($description)
                <p class="text-[11px] leading-snug mt-0.5 line-clamp-2 {{ $isSent ? 'text-indigo-100' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $description }}
                </p>
            @endif
        </div>
    </div>
</a>
