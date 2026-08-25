<x-app-layout :title="$loop->name">
    @php
        $_org = request()->route('organization');
        $_loopRoute = function ($name, $params = []) use ($_org) {
            if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
                return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
            }
            return route('loops.'.$name, $params);
        };
        // Legacy relation kept only to decide whether to show the block; the
        // names themselves come from owners() (CP5ter).
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
                            {{ __('loops.presentation_animator') }} <x-loops.owner-names :owners="$loop->owners" />
                        </span>
                    @endif
                </div>

                <x-loops.domain-badges :loop="$loop" class="mb-4" />

                @if($loop->description)
                    <p class="mb-6 whitespace-pre-line text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $loop->description }}</p>
                @endif

                {{-- Manifesto: only ever rendered when the controller resolved a
                     genuinely public one — published, on a non-private Loop. The
                     linked Dossiers documents are never surfaced here. --}}
                @if($publicManifesto ?? null)
                    @php($manifestoText = trim(strip_tags((string) ($publicManifesto->summary ?: $publicManifesto->content))))
                    <section class="mb-6 rounded-2xl border border-violet-100 bg-violet-50/50 p-4 dark:border-violet-900/40 dark:bg-violet-900/10">
                        <h2 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M6.75 2.994v2.25a2.25 2.25 0 0 1-2.25 2.25H2.25m11.4-3.75 4.6 4.6"/></svg>
                            {{ __('loops.manifesto_public_title') }}
                        </h2>
                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($manifestoText, 420) }}</p>
                        @if(mb_strlen($manifestoText) > 420)
                            {{-- TASK-1281 : l'Organization vient de la Boucle, jamais de la
                                 requete — la route nue retombe sur l'Organization par defaut
                                 (meme famille que LoopDossiersCard::articleUrls, T1111). --}}
                            <a href="{{ ($_slugOrg = $loop->organization?->slug) ? route('organization.blog.show', ['organization' => $_slugOrg, 'post' => $publicManifesto->slug]) : route('blog.show', $publicManifesto->slug) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-violet-700 hover:underline dark:text-violet-300">
                                {{ __('loops.manifesto_public_read_more') }}
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                            </a>
                        @endif
                    </section>
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
