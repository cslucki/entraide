<x-app-layout title="{{ __('ai.test_agent_title') }}">
    <x-page-container>
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.test_agent_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.test_agent_subtitle') }}</p>
        </div>

        @livewire('ai-agent-chat', ['user' => auth()->user()])
    </x-page-container>
</x-app-layout>
