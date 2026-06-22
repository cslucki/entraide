<x-app-layout title="{{ __('ai.wizard_title') }}">
    <x-page-container>
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.wizard_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.wizard_subtitle') }}</p>
        </div>
        @livewire('member-ai-profile-wizard')
    </x-page-container>
</x-app-layout>
