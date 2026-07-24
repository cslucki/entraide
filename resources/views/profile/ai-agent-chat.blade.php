<x-app-layout>
    <x-slot name="title">{{ __('ai.ai_agent_of', ['name' => $user->full_name]) }}</x-slot>

    @php
        $organizationRouteParam = request()->route('organization');
        $profileUrl = $organizationRouteParam && Route::has('organization.profile.show')
            ? route('organization.profile.show', ['organization' => $organizationRouteParam, 'user' => $user])
            : route('profile.show', $user);
    @endphp

    <div class="max-w-7xl mx-auto px-4 py-6" style="height: calc(100vh - 64px)">
        <div class="flex h-full border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden bg-white dark:bg-gray-800">
            <aside class="hidden sm:flex w-80 flex-shrink-0 flex-col border-r border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <a href="{{ $profileUrl }}" class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        {{ __('ai.back_to_profile') }}
                    </a>
                </div>

                <div class="p-5">
                    <div class="flex items-center gap-3">
                        <div class="relative flex-shrink-0">
                            <img src="{{ $user->avatar_url }}" class="w-12 h-12 rounded-full object-cover" alt="{{ $user->fullName }}">
                            <span class="absolute -bottom-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white ring-2 ring-gray-50 dark:ring-gray-900">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </span>
                        </div>
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ __('ai.profile_agent') }}</p>
                            <p class="text-xs text-green-600 dark:text-green-400">{{ __('ai.available') }}</p>
                        </div>
                    </div>

                    <p class="mt-5 text-sm leading-6 text-gray-600 dark:text-gray-400">
                        {{ __('ai.visitor_sidebar_description') }}
                    </p>

                    @auth
                        @if(auth()->id() !== $user->id)
                            <a href="{{ route('messages.with', $user) }}" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-white dark:hover:bg-gray-800 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                {{ __('ai.write_directly_to', ['name' => $user->full_name]) }}
                            </a>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-white dark:hover:bg-gray-800 transition">
                            {{ __('ai.login_to_write') }}
                        </a>
                    @endauth
                </div>
            </aside>

            <section class="flex-1 min-w-0 flex flex-col bg-white dark:bg-gray-800">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3 sm:hidden">
                    <a href="{{ $profileUrl }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300" aria-label="{{ __('ai.back_to_profile') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ __('ai.profile_agent') }}</p>
                        <p class="text-xs text-green-600 dark:text-green-400">{{ __('ai.available') }}</p>
                    </div>
                </div>

                <div class="flex-1 min-h-0 flex flex-col bg-gray-50 dark:bg-gray-900/30">
                    @livewire('ai-agent-chat', ['user' => $user])
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
