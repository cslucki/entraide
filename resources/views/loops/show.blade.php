@php
    $currentLoop = $loop;
    $analysis = session('help_request_analysis');
    $_org = request()->route('organization');
    $_loopRoute = function ($name, $params = []) use ($_org) {
        if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
            return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
        }
        return route('loops.'.$name, $params);
    };
    $_aiRoute = $_loopRoute('ai', ['loop' => $currentLoop]);
    $canUseWorkspaceCards = $isMember || (bool) auth()->user()?->is_admin;
    $workspaceCards = collect(config('loop_cards.cards', []))
        ->filter(fn ($card) => (bool) ($card['default_enabled'] ?? false))
        ->when(! $canUseWorkspaceCards, fn ($cards) => $cards->filter(fn () => false))
        ->sortBy('order')
        ->values();
@endphp

@push('head')
<style>
    @media (max-width: 767px) {
        body:has(.loops-show-container) > header[class*="fixed"],
        body:has(.loops-show-container) > [class*="md:hidden"]:has(button[class*="bottom-20"]) {
            display: none !important;
        }
        body:has(.loops-show-container) > .min-h-screen {
            padding-top: 0 !important;
            padding-bottom: calc(4rem + env(safe-area-inset-bottom, 0px)) !important;
        }
        body:has(.loops-show-container) .min-h-screen > .md\:hidden,
        body:has(.loops-show-container) .min-h-screen > footer {
            display: none !important;
        }
        body:has(.loops-show-container) .loops-show-wrapper {
            padding: 0 !important;
        }
        body:has(.loops-show-container) .loops-show-container {
            height: calc(100dvh - 4rem - env(safe-area-inset-bottom, 0px));
        }
    }
    @media (min-width: 768px) {
        .loops-show-container {
            height: calc(100vh - 5rem);
        }
    }
</style>
@endpush

<x-app-layout :title="$currentLoop->name">
    <x-page-container class="loops-show-wrapper">
        <div
            x-data="{ activeCard: null, openCard(card) { this.activeCard = this.activeCard === card ? null : card }, closeCard() { this.activeCard = null } }"
            x-effect="document.body.style.overflow = activeCard && window.matchMedia('(max-width: 1023px)').matches ? 'hidden' : ''"
            @keydown.escape.window="closeCard()"
            class="loops-show-container h-dvh flex flex-col bg-white dark:bg-gray-800"
            data-loop-workspace-shell
        >

        {{-- Topbar --}}
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            @php $backHome = app()->bound('current_organization') && app('current_organization')->isMonoLoop(); @endphp
            <a href="{{ $backHome ? route('home') : $_loopRoute('index') }}"
               class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
               aria-label="{{ $backHome ? __('loops.back_home') : __('loops.back_to_loops') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div class="min-w-0 flex-1 sm:flex sm:items-center sm:gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-start gap-2">
                        <h1 class="truncate text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $currentLoop->name }}</h1>
                        <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full border px-1 py-px text-[8px] font-semibold uppercase tracking-wide {{ $currentLoop->isPublic() ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' }}">
                            {{ $currentLoop->isPublic() ? __('loops.visibility_public') : __('loops.visibility_private') }}
                        </span>
                    </div>
                    @if($currentLoop->description)
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $currentLoop->description }}</p>
                    @endif
                </div>

                @if($isMember && config('ai.chatloop.enabled', true))
                <div class="mt-2 flex flex-nowrap items-center justify-start gap-2 overflow-x-auto pb-0.5 sm:mt-0 sm:ml-auto sm:justify-end sm:overflow-visible sm:pb-0" x-data="{ askOpen: false, asking: false, question: '' }">
                    <form
                        method="POST"
                        action="{{ $_aiRoute }}"
                        x-data="{ submitting: false }"
                        x-on:submit="submitting = true"
                    >
                        @csrf
                        <input type="hidden" name="action" value="answer">
                        <button
                            type="submit"
                            x-bind:disabled="submitting"
                            class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl border border-violet-100 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 shadow-sm transition hover:border-violet-200 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-800/50 dark:bg-violet-900/30 dark:text-violet-200 dark:hover:bg-violet-900/50"
                        >
                            <svg x-show="!submitting" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/>
                            </svg>
                            <svg x-show="submitting" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-show="!submitting" class="sm:hidden">{{ __('loops.ask_ai_short') }}</span>
                            <span x-show="!submitting" class="hidden sm:inline">{{ __('loops.ask_ai') }}</span>
                            <span x-show="submitting" x-cloak>{{ __('loops.ai_generating') }}</span>
                        </button>
                    </form>

                    <button
                        type="button"
                        x-on:click="askOpen = true"
                        class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm transition hover:border-violet-200 hover:text-violet-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-violet-700 dark:hover:text-violet-200"
                    >
                        <svg class="h-4 w-4 text-violet-600 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-5l-5 4v-4z"/>
                        </svg>
                        <span class="sm:hidden">{{ __('loops.ask_question_short') }}</span>
                        <span class="hidden sm:inline">{{ __('loops.ask_question') }}</span>
                    </button>

                    <template x-teleport="body">
                        <div
                            x-show="askOpen"
                            x-cloak
                            class="fixed inset-0 z-50 flex items-center justify-center"
                            x-effect="document.body.style.overflow = askOpen ? 'hidden' : ''"
                            @keydown.escape.window="askOpen = false"
                        >
                            <div x-show="askOpen" class="fixed inset-0 bg-black/50"></div>
                            <form
                                method="POST"
                                action="{{ $_aiRoute }}"
                                x-show="askOpen"
                                x-on:submit="asking = true"
                                class="relative mx-3 w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800"
                            >
                                @csrf
                                <input type="hidden" name="action" value="ask">
                                <label for="ai-question" class="block text-sm font-medium text-gray-800 dark:text-gray-200">
                                    {{ __('loops.ask_question') }}
                                </label>
                                <input
                                    id="ai-question"
                                    type="text"
                                    name="question"
                                    x-model="question"
                                    required
                                    maxlength="500"
                                    placeholder="{{ __('loops.ask_question_placeholder') }}"
                                    class="mt-2 w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                >
                                <div class="mt-4 flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        x-on:click="askOpen = false"
                                        class="text-xs font-medium text-gray-600 transition hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
                                    >
                                        {{ __('loops.cancel') }}
                                    </button>
                                    <button
                                        type="submit"
                                        x-bind:disabled="asking"
                                        class="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span x-show="!asking">{{ __('loops.ask_question_submit') }}</span>
                                        <span x-show="asking" x-cloak>{{ __('loops.ai_generating') }}</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </template>
                </div>
                @endif
            </div>
        </div>

        {{-- Session messages --}}
        @if(session('success') && session('success') !== 'Message envoyé.')
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="flex-shrink-0 bg-green-50 dark:bg-green-900/20 border-b border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-2 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                 class="flex-shrink-0 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-2 text-sm">
                {{ session('error') }}
            </div>
        @endif
        @if(session('help_request_error'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
                 class="flex-shrink-0 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-2 text-sm">
                {{ session('help_request_error') }}
            </div>
        @endif

        {{-- Boucle Workspace Cards shell --}}
        @if($workspaceCards->isNotEmpty())
            <div class="flex-shrink-0 border-b border-gray-200 bg-gray-50/80 px-4 py-2 dark:border-gray-700 dark:bg-gray-900/30">
                <div class="flex items-center gap-3 overflow-x-auto pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <div class="hidden shrink-0 sm:block">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.cards_bar_label') }}</p>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('loops.cards_bar_hint') }}</p>
                    </div>

                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        @foreach($workspaceCards as $card)
                            <button
                                type="button"
                                x-on:click="openCard(@js($card['key']))"
                                x-bind:aria-pressed="activeCard === @js($card['key'])"
                                x-bind:class="activeCard === @js($card['key']) ? 'border-violet-200 bg-violet-50 text-violet-800 shadow-sm dark:border-violet-700/70 dark:bg-violet-900/30 dark:text-violet-100' : 'border-gray-200 bg-white text-gray-600 hover:border-violet-200 hover:text-violet-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-violet-700 dark:hover:text-violet-200'"
                                class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-2xl border px-3 py-2 text-xs font-semibold transition"
                            >
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300" aria-hidden="true">
                                    @switch($card['icon'] ?? '')
                                        @case('sparkles')
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/></svg>
                                            @break
                                        @case('document')
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3v4.5h4.5"/></svg>
                                            @break
                                        @case('map')
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18-6 3V6l6-3m0 15 6 3m-6-3V3m6 18 6-3V3l-6 3m0 15V6m0 0L9 3"/></svg>
                                            @break
                                        @default
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m0 0 3-3m-3 3-3-3M15 9v8.25M15 17.25l3-3m-3 3-3-3"/></svg>
                                    @endswitch
                                </span>
                                <span>{{ __($card['label_key']) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Messages + Composer (Livewire) + Cards panel --}}
        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <div
                x-bind:class="activeCard ? 'lg:basis-3/5 lg:max-w-[60%]' : 'lg:basis-full lg:max-w-full'"
                x-bind:data-card-active="activeCard ? 'true' : 'false'"
                class="flex min-h-0 flex-1 flex-col transition-[flex-basis,max-width] duration-200"
                data-loop-workspace-chat
            >
                @livewire('loop-chat', ['loop' => $currentLoop], key('loop-chat-'.$currentLoop->id))
            </div>

            @if($workspaceCards->isNotEmpty())
                <aside
                    x-show="activeCard"
                    x-cloak
                    x-transition.opacity.duration.150ms
                    class="fixed inset-0 z-40 flex flex-col border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 lg:relative lg:inset-auto lg:z-auto lg:w-[40%] lg:max-w-[40%] lg:shadow-none"
                    aria-label="{{ __('loops.cards_bar_label') }}"
                    data-loop-workspace-panel
                >
                    <div class="flex min-h-0 flex-1 flex-col">
                        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <button
                                type="button"
                                x-on:click="closeCard()"
                                class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-violet-200 hover:text-violet-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-violet-700 dark:hover:text-violet-200 lg:hidden"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                {{ __('loops.cards_panel_back_to_chat') }}
                            </button>

                            <p class="hidden text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 lg:block">{{ __('loops.cards_bar_label') }}</p>

                            <button
                                type="button"
                                x-on:click="closeCard()"
                                class="ml-auto inline-flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                aria-label="{{ __('loops.cards_panel_close') }}"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
                            @foreach($workspaceCards as $card)
                                <section x-show="activeCard === @js($card['key'])" x-cloak class="space-y-5">
                                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('loops.cards_panel_temporary_state') }}</p>
                                        <h2 class="mt-2 text-lg font-semibold text-gray-950 dark:text-gray-50">{{ __($card['label_key']) }}</h2>
                                        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __($card['description_key']) }}</p>
                                    </div>

                                    <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center dark:border-gray-700 dark:bg-gray-900">
                                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-200">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0z"/></svg>
                                        </div>
                                        <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __($card['empty_title_key']) }}</h3>
                                        <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __($card['empty_body_key']) }}</p>
                                        <button
                                            type="button"
                                            disabled
                                            class="mt-5 inline-flex items-center justify-center rounded-xl border border-gray-200 bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500"
                                        >
                                            {{ __($card['action_key']) }} · {{ __('loops.cards_panel_coming_soon') }}
                                        </button>
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </div>
                </aside>
            @endif
        </div>

        <x-conversation.image-lightbox key="loop-chat" />

        {{-- Composer --}}
        <div class="flex-shrink-0 border-t border-gray-200 dark:border-gray-700">
            @if(!$isMember && $currentLoop->isPublic())
                <div class="px-4 py-3">
                    <form method="POST" action="{{ $_loopRoute('join', ['loop' => $currentLoop]) }}">
                        @csrf
                        <button type="submit"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            {{ __('loops.join') }}
                        </button>
                    </form>
                </div>

            @elseif($clarificationEnabled || $analysis)
                <div x-data="{ open: @js($analysis ? true : false) }" class="px-4 py-3">
                    <button x-show="!open" @click="open = true"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl hover:bg-amber-100 dark:hover:bg-amber-900/30 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <span>{{ __('loops.who_can_help') }}</span>
                    </button>

                    <template x-teleport="body">
                        <div x-show="open" x-cloak
                            class="fixed inset-0 z-50 flex items-center justify-center"
                            x-effect="document.body.style.overflow = open ? 'hidden' : ''"
                            @keydown.escape.window="open = false">
                            <div x-show="open" @click="open = false" class="fixed inset-0 bg-black/50 transition-opacity"></div>
                            <div x-show="open" @click.away="open = false"
                                class="relative w-full max-w-lg bg-white dark:bg-gray-800 rounded-2xl shadow-xl flex flex-col max-h-[80vh] mx-3">
                                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        @if($analysis)
                                            {{ __('loops.clarified_request') }}
                                        @else
                                            {{ __('loops.who_can_help') }}
                                        @endif
                                    </h3>
                                    @if($analysis)
                                        <a href="{{ $_loopRoute('show', ['loop' => $currentLoop]) }}" class="p-1 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </a>
                                    @else
                                        <button @click="open = false" class="p-1 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    @endif
                                </div>
                                <div class="overflow-y-auto px-4 py-3 min-h-0 flex-1">
                                    @if($analysis)
                                        @php
                                            $needsFallback = $analysis['fallback']['needed'] ?? false;
                                            $fallbackReason = $analysis['fallback']['reason'] ?? null;
                                            $fallbackQuestions = $analysis['fallback']['questions'] ?? [];
                                            $originalPhrase = $analysis['original_phrase'] ?? session('help_request_intention', '');
                                            $fallbackNeedEmpty = $needsFallback && empty($analysis['need']) && $originalPhrase;
                                            $needValue = $fallbackNeedEmpty ? $originalPhrase : ($analysis['need'] ?? '');
                                            $selectedHelpType = old('help_type', ($analysis['intent'] ?? '') === 'offer' ? 'service' : 'request');
                                        @endphp

                                        @if($needsFallback)
                                            <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-700/50 rounded-lg p-3 text-sm text-orange-700 dark:text-orange-300 mb-3">
                                                <p class="font-medium mb-1">{{ __('loops.precision_needed') }}</p>
                                                <p class="mb-1">{{ $fallbackReason }}</p>
                                                <p class="mb-2 text-xs">{{ __('loops.ia_ko_message') }}</p>
                                                @if($originalPhrase)
                                                    <div class="p-2 bg-white dark:bg-gray-800 rounded border border-orange-200 dark:border-orange-700 text-gray-600 dark:text-gray-400 text-xs italic">
                                                        « {{ $originalPhrase }} »
                                                    </div>
                                                @endif
                                                @if(count($fallbackQuestions))
                                                    <ul class="list-disc list-inside mt-2 space-y-0.5">
                                                        @foreach($fallbackQuestions as $q)
                                                            <li>{{ $q }}</li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </div>
                                        @endif

                                        <form method="POST" action="{{ $_loopRoute('help-request.publish', ['loop' => $currentLoop]) }}" class="space-y-3">
                                            @csrf
                                            <div>
                                                <label for="hr-title" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('loops.form_title') }}</label>
                                                <input type="text" name="title" id="hr-title" value="{{ old('title', $analysis['title'] ?? '') }}" maxlength="120"
                                                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                                @error('title')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label for="hr-need" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('loops.description') }}</label>
                                                <textarea name="need" id="hr-need" rows="3" maxlength="2000"
                                                    class="w-full resize-none px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('need', $needValue) }}</textarea>
                                                @error('need')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">{{ __('loops.exchange_type') }}</label>
                                                <div class="flex gap-3">
                                                    <label class="flex items-center gap-2 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-1 has-[:checked]:ring-indigo-500">
                                                        <input type="radio" name="help_type" value="request" @checked($selectedHelpType === 'request')
                                                            class="text-indigo-600 focus:ring-indigo-500">
                                                        {{ __('loops.help_type_request') }}
                                                    </label>
                                                    <label class="flex items-center gap-2 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-1 has-[:checked]:ring-indigo-500">
                                                        <input type="radio" name="help_type" value="service" @checked($selectedHelpType === 'service')
                                                            class="text-indigo-600 focus:ring-indigo-500">
                                                        {{ __('loops.help_type_service') }}
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="flex gap-3 pt-1">
                                                <a href="{{ $_loopRoute('show', ['loop' => $currentLoop]) }}"
                                                   class="flex-1 text-center px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-xl transition">
                                                    {{ __('loops.cancel') }}
                                                </a>
                                                <button type="submit"
                                                    class="flex-1 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    {{ __('loops.continue_to_exchanges') }}
                                                </button>
                                            </div>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ $_loopRoute('help-request.analyze', ['loop' => $currentLoop]) }}" class="space-y-3">
                                            @csrf
                                            <label for="intention" class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                                {{ __('loops.describe_need') }}
                                            </label>
                                            <textarea name="intention" id="intention" rows="3"
                                                placeholder="{{ __('loops.intention_placeholder') }}"
                                                class="w-full resize-none px-3.5 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-amber-400 focus:border-transparent"
                                                required minlength="3"></textarea>
                                            <button type="submit"
                                                class="w-full px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                                                </svg>
                                                {{ __('loops.clarify_request') }}
                                            </button>
                                        </form>
                                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-center">{{ __('loops.help_booster_ai') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

            @elseif(session('help_request_error'))
                <div class="px-4 py-3">
                    <div class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400 mb-3">
                        <span>{{ session('help_request_error') }}</span>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ $_loopRoute('show', ['loop' => $currentLoop]) }}"
                           class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-xl transition">
                            {{ __('loops.back') }}
                        </a>
                    </div>
                </div>

            @endif
        </div>
    </div>
    </x-page-container>
</x-app-layout>
