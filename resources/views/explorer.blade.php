<x-app-layout :title="__('explorer.title')">
    <x-page-container>
        <h1 class="hidden md:block text-3xl font-bold text-gray-900 dark:text-gray-100 mb-8">{{ __('explorer.title') }}</h1>
        @livewire('explorer')
    </x-page-container>
</x-app-layout>
