@props([
    'title' => null,
    'subtitle' => null,
    'avatar' => null,
    'status' => null,
    'class' => '',
    'titleClass' => 'text-lg',
    'subtitleClass' => 'text-sm text-gray-500 dark:text-gray-400',
])

<div class="flex items-center gap-3 p-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0 {{ $class }}">
    @if(isset($icon))
        <div class="relative">
            {{ $icon }}
            @if($status === 'online')
            <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800"></span>
            @elseif($status === 'offline')
            <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-gray-400 rounded-full border-2 border-white dark:border-gray-800"></span>
            @endif
        </div>
    @elseif($avatar)
    <div class="relative">
        <img src="{{ $avatar }}" alt="" class="w-9 h-9 rounded-full">
        @if($status === 'online')
        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800"></span>
        @elseif($status === 'offline')
        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-gray-400 rounded-full border-2 border-white dark:border-gray-800"></span>
        @endif
    </div>
    @endif

    @if($title || $subtitle)
    <div class="flex-1 min-w-0">
        @if($title)
        <p class="{{ $titleClass }} font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $title }}</p>
        @endif
        @if($subtitle)
        <p class="{{ $subtitleClass }}">{{ $subtitle }}</p>
        @endif
    </div>
    @endif

    @if(isset($actions))
    <div class="ml-auto flex items-center gap-2">
        {{ $actions }}
    </div>
    @endif
</div>
