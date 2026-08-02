<x-app-layout :title="$loop->name">
    @php
        $_org = request()->route('organization');
        $_loopRoute = function ($name, $params = []) use ($_org) {
            if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
                return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
            }
            return route('loops.'.$name, $params);
        };
        $ownerUser = $loop->owner?->user;
    @endphp

    <div class="mx-auto max-w-3xl px-4 py-8">
        <a href="{{ $_loopRoute('index') }}" class="mb-4 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            {{ __('loops.back_to_loops') }}
        </a>

        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('success') }}</div>
        @endif
        @if(session('info'))
            <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300">{{ session('info') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <x-loops.cover :loop="$loop" ratio="21 / 9" />

            <div class="p-6 sm:p-8">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $loop->name }}</h1>
                    <x-loops.access-badge :loop="$loop" />
                </div>

                @if($loop->tagline)
                    <p class="mb-4 text-lg font-medium text-gray-700 dark:text-gray-300">{{ $loop->tagline }}</p>
                @endif

                <div class="mb-6 flex flex-wrap items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        {{ trans_choice('loops.presentation_members_count', $loop->active_members_count, ['count' => $loop->active_members_count]) }}
                    </span>
                    @if($ownerUser)
                        <span class="flex items-center gap-1.5">
                            @if($ownerUser->avatar_url)
                                <img src="{{ $ownerUser->avatar_url }}" alt="" class="h-5 w-5 rounded-full object-cover">
                            @endif
                            {{ __('loops.presentation_animator') }} {{ $ownerUser->publicDisplayName() }}
                        </span>
                    @endif
                </div>

                @if($loop->description)
                    <p class="mb-6 whitespace-pre-line text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $loop->description }}</p>
                @endif

                {{-- CTA --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-900/40">
                    @if($loop->isOpenAccess())
                        <form method="POST" action="{{ $_loopRoute('join', ['loop' => $loop]) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 sm:w-auto">
                                {{ __('loops.cta_join') }}
                            </button>
                        </form>
                    @elseif($loop->isRequestAccess())
                        @if($pendingRequest)
                            <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">{{ __('loops.join_request_pending_notice') }}</p>
                            <form method="POST" action="{{ route('loop-join-requests.cancel', $pendingRequest) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                    {{ __('loops.cta_cancel_request') }}
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ $_loopRoute('join-requests.store', ['loop' => $loop]) }}" class="space-y-3">
                                @csrf
                                <div>
                                    <label for="message" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('loops.join_request_message_label') }}</label>
                                    <textarea name="message" id="message" rows="2" maxlength="500"
                                              placeholder="{{ __('loops.join_request_message_placeholder') }}"
                                              class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">{{ old('message') }}</textarea>
                                </div>
                                <button type="submit"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 sm:w-auto">
                                    {{ __('loops.cta_request') }}
                                </button>
                            </form>
                        @endif
                    @else
                        <p class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                            </svg>
                            {{ __('loops.cta_invitation') }}
                        </p>
                    @endif
                </div>

                <div class="mt-6 flex items-start gap-2 text-xs text-gray-400 dark:text-gray-500">
                    <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                    </svg>
                    <span><strong class="font-medium text-gray-500 dark:text-gray-400">{{ __('loops.presentation_locked_title') }}.</strong> {{ __('loops.presentation_locked_body') }}</span>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
