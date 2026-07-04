<x-app-layout title="{{ __('ai.conversation_detail_title') }}">
    @php
        $_isOrgRoute = str_starts_with(Route::currentRouteName(), 'organization.');
        $_orgSlug = $_isOrgRoute ? currentOrganization()?->slug : null;
        $_conversationsUrl = $_orgSlug && Route::has('organization.agent-ia.conversations') ? route('organization.agent-ia.conversations', ['organization' => $_orgSlug]) : route('agent-ia.conversations');
        $_wizardUrl = $_orgSlug && Route::has('organization.agent-ia.wizard') ? route('organization.agent-ia.wizard', ['organization' => $_orgSlug]) : route('agent-ia.wizard');
    @endphp

    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <a href="{{ $_conversationsUrl }}"
                   class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                    </svg>
                    {{ __('ai.back_to_conversations') }}
                </a>
            </div>
            <a href="{{ $_wizardUrl }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                {{ __('ai.edit_profile') }}
            </a>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-2 border-b border-gray-100 pb-4 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.visitor_information') }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ $conversation->visitor?->name ?? __('ai.visitor_anonymous') }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('ai.conversation_date') }} : {{ $conversation->created_at->format('d/m/Y H:i') }}
                    </p>
                </div>
                @if($conversation->visitor_user_id)
                    <span class="inline-flex w-fit rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                        {{ __('ai.visitor_user') }}
                    </span>
                @else
                    <span class="inline-flex w-fit rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                        {{ __('ai.visitor_anonymous_type') }}
                    </span>
                @endif
            </div>

            @if($conversation->title)
                <div class="mt-3 pb-4 border-b border-gray-100 dark:border-gray-700">
                    <p class="text-sm italic text-gray-600 dark:text-gray-400">"{{ $conversation->title }}"</p>
                </div>
            @endif

            <div class="mt-4 space-y-4">
                @foreach($messages as $message)
                    <div class="flex {{ $message->role === 'user' ? 'justify-start' : 'justify-end' }}">
                        <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm {{ $message->role === 'user' ? 'bg-gray-100 text-gray-900 dark:bg-gray-700 dark:text-gray-100' : 'bg-indigo-50 text-gray-900 dark:bg-indigo-900/40 dark:text-gray-100' }}">
                            <p class="text-xs font-semibold mb-1 {{ $message->role === 'user' ? 'text-gray-500 dark:text-gray-400' : 'text-indigo-600 dark:text-indigo-400' }}">
                                {{ $message->role === 'user' ? __('ai.message_role_user') : __('ai.message_role_assistant') }}
                            </p>
                            <p class="whitespace-pre-wrap">{{ $message->content }}</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 text-right">
                                {{ $message->created_at->format('H:i') }}
                            </p>
                            @if($message->metadata && ($message->metadata['provider'] ?? null))
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-400">
                                        {{ __('ai.technical_details') }}
                                    </summary>
                                    <div class="mt-1 space-y-0.5 text-xs text-gray-400 dark:text-gray-500">
                                        @if($message->metadata['provider'] ?? null)
                                            <p>{{ __('ai.provider_label') }} : {{ $message->metadata['provider'] }}</p>
                                        @endif
                                        @if($message->metadata['model'] ?? null)
                                            <p>{{ __('ai.model_label') }} : {{ $message->metadata['model'] }}</p>
                                        @endif
                                        @if($message->metadata['latency_ms'] ?? null)
                                            <p>{{ __('ai.latency_label') }} : {{ trans_choice('ai.latency_ms', $message->metadata['latency_ms'], ['ms' => $message->metadata['latency_ms']]) }}</p>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
