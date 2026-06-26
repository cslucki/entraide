@php
    $title = __('subscriptions.page_title');
@endphp

<x-app-layout :title="$organization->platform_name ?? $organization->name">
    <x-page-container>
        <div class="max-w-4xl mx-auto space-y-8">
            <div class="text-center space-y-3">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('subscriptions.heading') }}</h1>
                <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto">{{ __('subscriptions.subheading') }}</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Card AMT --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-indigo-200 dark:border-indigo-700 p-6 flex flex-col">
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('subscriptions.plan_amt_name') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('subscriptions.plan_amt_desc') }}</p>
                    </div>
                    <div class="mb-4">
                        <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">5</span>
                        <span class="text-gray-500 dark:text-gray-400"> EUR / {{ __('subscriptions.month') }}</span>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('subscriptions.plan_amt_body') }}</p>
                    </div>
                    <button type="button" disabled
                        class="mt-6 w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm font-medium rounded-xl cursor-not-allowed">
                        {{ __('subscriptions.coming_soon') }}
                    </button>
                </div>

                {{-- Card Formule 1 --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex flex-col">
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('subscriptions.plan_f1_name') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('subscriptions.plan_f1_desc') }}</p>
                    </div>
                    <div class="mb-4">
                        <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">19,99</span>
                        <span class="text-gray-500 dark:text-gray-400"> EUR / {{ __('subscriptions.month') }}</span>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('subscriptions.plan_f1_body') }}</p>
                    </div>
                    <button type="button" disabled
                        class="mt-6 w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm font-medium rounded-xl cursor-not-allowed">
                        {{ __('subscriptions.coming_soon') }}
                    </button>
                </div>

                {{-- Card Formule 2 --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 flex flex-col">
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('subscriptions.plan_f2_name') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('subscriptions.plan_f2_desc') }}</p>
                    </div>
                    <div class="mb-4">
                        <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">44,99</span>
                        <span class="text-gray-500 dark:text-gray-400"> EUR / {{ __('subscriptions.month') }}</span>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('subscriptions.plan_f2_body') }}</p>
                    </div>
                    <button type="button" disabled
                        class="mt-6 w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm font-medium rounded-xl cursor-not-allowed">
                        {{ __('subscriptions.coming_soon') }}
                    </button>
                </div>

                {{-- Card Formule Annuelle --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-amber-200 dark:border-amber-700 p-6 flex flex-col relative">
                    <div class="absolute -top-3 right-4">
                        <span class="px-3 py-1 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-xs font-semibold rounded-full">{{ __('subscriptions.best_value') }}</span>
                    </div>
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('subscriptions.plan_annual_name') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('subscriptions.plan_annual_desc') }}</p>
                    </div>
                    <div class="mb-4">
                        <span class="text-3xl font-bold text-gray-900 dark:text-gray-100">690</span>
                        <span class="text-gray-500 dark:text-gray-400"> EUR / {{ __('subscriptions.year') }}</span>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('subscriptions.plan_annual_body') }}</p>
                    </div>
                    <button type="button" disabled
                        class="mt-6 w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm font-medium rounded-xl cursor-not-allowed">
                        {{ __('subscriptions.coming_soon') }}
                    </button>
                </div>
            </div>

            <div class="text-center">
                <a href="{{ route(currentOrganization() ? 'organization.home' : 'home', currentOrganization() ? [currentOrganization()?->slug] : []) }}"
                   class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                    &larr; {{ __('subscriptions.back_to_home') }}
                </a>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
