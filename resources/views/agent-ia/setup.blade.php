<x-app-layout title="{{ __('ai.setup_title') }}">
    <x-page-container>
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.setup_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.setup_subtitle') }}</p>
        </div>

        @livewire('member-ai-profile-conversational-setup')
    </x-page-container>
</x-app-layout>
