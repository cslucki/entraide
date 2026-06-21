@props(['title' => '', 'heading' => '', 'width' => '7xl'])

<x-app-layout :title="filled($title) ? $title : $heading">
    <x-page-container :width="$width">
        @if($heading)
            <div class="hidden md:flex items-center justify-between mb-8">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $heading }}
                </h1>
                @isset($headingActions)
                    {{ $headingActions }}
                @endisset
            </div>
        @endif

        {{ $slot }}
    </x-page-container>
</x-app-layout>
