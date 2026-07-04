<x-app-layout title="{{ __('ai.conversations_title') }}">
    @php
        $_isOrgRoute = str_starts_with(Route::currentRouteName(), 'organization.');
        $_orgSlug = $_isOrgRoute ? currentOrganization()?->slug : null;
        $_wizardUrl = $_orgSlug && Route::has('organization.agent-ia.wizard') ? route('organization.agent-ia.wizard', ['organization' => $_orgSlug]) : route('agent-ia.wizard');
        $_interactionsUrl = $_orgSlug && Route::has('organization.agent-ia.interactions') ? route('organization.agent-ia.interactions', ['organization' => $_orgSlug]) : route('agent-ia.interactions');
        $_conversationsUrl = $_orgSlug && Route::has('organization.agent-ia.conversations') ? route('organization.agent-ia.conversations', ['organization' => $_orgSlug]) : route('agent-ia.conversations');
    @endphp

    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.conversations_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('ai.conversations_subtitle') }}</p>
            </div>
            <a href="{{ $_wizardUrl }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                {{ __('ai.edit_profile') }}
            </a>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-6">
            <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <a href="{{ $_interactionsUrl }}"
                   class="px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('ai.tab_interactions') }}
                </a>
                <a href="{{ $_conversationsUrl }}"
                   class="px-4 py-2 text-sm font-medium text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-900/30 transition">
                    {{ __('ai.tab_conversations') }}
                </a>
            </div>
        </div>

        @if(!$profile)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.no_profile_title') }}</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('ai.no_profile_body') }}</p>
                <a href="{{ $_wizardUrl }}" class="mt-5 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('ai.create_profile') }}</a>
            </div>
        @elseif($conversations->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.no_conversations_title') }}</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('ai.no_conversations_body') }}</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($conversations as $conversation)
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800" wire:key="conversation-{{ $conversation->id }}">
                        <div class="flex flex-col gap-2 border-b border-gray-100 pb-4 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $conversation->visitor?->name ?? __('ai.visitor_anonymous') }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $conversation->created_at->format('d/m/Y H:i') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                    {{ trans_choice('ai.messages_count', $conversation->messages_count, ['count' => $conversation->messages_count]) }}
                                </span>
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
                        </div>

                        @if($conversation->title)
                            <div class="mt-3">
                                <p class="text-sm italic text-gray-600 dark:text-gray-400">"{{ $conversation->title }}"</p>
                            </div>
                        @endif

                        <div class="mt-4">
                            <a href="{{ $_orgSlug && Route::has('organization.agent-ia.conversations.show') ? route('organization.agent-ia.conversations.show', ['organization' => $_orgSlug, 'conversation' => $conversation]) : route('agent-ia.conversations.show', $conversation) }}"
                               class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                                {{ __('ai.view_conversation') }}
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                </svg>
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $conversations->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
