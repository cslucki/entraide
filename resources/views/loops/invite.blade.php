<x-app-layout :title="__('loops.invite_title')">
    @php
        // Aliased: no @foreach shadowing risk, and consistent with show/edit.
        $currentLoop = $loop;
        $_org = request()->route('organization');
        $_loopRoute = function ($name, $params = []) use ($_org) {
            if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
                return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
            }
            return route('loops.'.$name, $params);
        };
    @endphp

    <div class="mx-auto max-w-2xl px-4 py-8">
        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        <div class="mb-6 text-center">
            <span class="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                </svg>
            </span>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.invite_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.invite_subtitle') }}</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.invite_add_members_title') }}</h2>

            @if($candidates->isEmpty())
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.invite_no_candidate') }}</p>
            @else
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('loops.invite_add_members_help') }}</p>

                {{-- A plain checkbox list: an Organization holds ~200 people at most,
                     so a local filter is enough — no server-side search needed. --}}
                <form method="POST" action="{{ $_loopRoute('invite.members', ['loop' => $currentLoop]) }}"
                      x-data="{ q: '', picked: [] }" class="mt-4">
                    @csrf

                    @if($candidates->count() > 8)
                        <input type="search" x-model="q" placeholder="{{ __('loops.invite_search_placeholder') }}"
                               class="mb-3 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @endif

                    <div class="max-h-72 space-y-1 overflow-y-auto rounded-xl border border-gray-200 p-2 dark:border-gray-700">
                        @foreach($candidates as $candidate)
                            <label x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($candidate->publicDisplayName())) }}.includes(q.toLowerCase())"
                                   class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 hover:bg-gray-50 has-[:checked]:bg-indigo-50 dark:hover:bg-gray-700/50 dark:has-[:checked]:bg-indigo-900/20">
                                <input type="checkbox" name="user_ids[]" value="{{ $candidate->id }}" x-model="picked"
                                       class="rounded text-indigo-600 focus:ring-indigo-500">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ mb_strtoupper(mb_substr($candidate->publicDisplayName(), 0, 1)) }}
                                </span>
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-200">{{ $candidate->publicDisplayName() }}</span>
                            </label>
                        @endforeach
                    </div>

                    <button type="submit" x-bind:disabled="picked.length === 0"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto">
                        <span x-show="picked.length === 0">{{ __('loops.invite_add_submit') }}</span>
                        <span x-show="picked.length > 0" x-cloak x-text="`{{ __('loops.invite_add_submit') }} (${picked.length})`"></span>
                    </button>
                </form>
            @endif
        </div>

        <div class="mt-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.invite_email_title') }}</h2>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('loops.invite_email_help') }}</p>

            <form method="POST" action="{{ $_loopRoute('invitations.store', ['loop' => $currentLoop]) }}" class="mt-4 space-y-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.invite_email_label') }}</span>
                        <input type="email" name="email" required
                               class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </label>
                    <label class="block">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.invite_email_name_label') }}</span>
                        <input type="text" name="name"
                               class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </label>
                </div>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.invite_email_message_label') }}</span>
                    <textarea name="message" rows="2"
                              class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </label>
                @error('email')
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <button type="submit"
                        class="inline-flex w-full items-center justify-center rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white sm:w-auto">
                    {{ __('loops.invite_email_submit') }}
                </button>
            </form>

            <x-loops.invitation-list :invitations="$pendingInvitations" class="mt-5" />
        </div>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-between">
            <a href="{{ $_loopRoute('index') }}"
               class="inline-flex items-center justify-center rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                {{ __('loops.invite_later') }}
            </a>
            <a href="{{ $_loopRoute('show', ['loop' => $currentLoop]) }}"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                {{ __('loops.cta_open_workspace') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                </svg>
            </a>
        </div>
    </div>
</x-app-layout>
