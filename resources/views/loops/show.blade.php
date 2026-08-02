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
    // NOTE: a primary Manifesto designation (loops.manifesto_blog_post_id) is a future
    // dedicated task. We deliberately do NOT auto-pick the first linked BlogPost as "the
    // Manifesto" here, to avoid presenting an arbitrary article as the reference document.
    // Per-card accent (icon tile) to echo the mockup's calm colour coding.
    $cardAccents = [
        'core.ai_summary' => 'bg-violet-100 text-violet-600 dark:bg-violet-500/20 dark:text-violet-300',
        'core.manifesto' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300',
        'core.roadmap' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300',
        'core.members' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300',
    ];
    $loopMembers = $currentLoop->members->where('status', 'active')->sortBy(fn ($m) => match ($m->role) {
        'owner' => 0, 'moderator' => 1, default => 2,
    });
    $inviteAction = ($_org && request()->routeIs('organization.*') && Route::has('organization.points.invitation.send'))
        ? route('organization.points.invitation.send', ['organization' => $_org])
        : route('points.invitation.send');
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
        /* No top app header on this page (loops.show sets no $header slot), so the
           workspace fills the full viewport height — otherwise ~5rem stays empty. */
        .loops-show-container {
            height: 100dvh;
        }
        /* Full-width workspace on desktop: drop the max-w-7xl / page padding so the
           two cards use the whole available width instead of a narrow centred column. */
        .loops-show-wrapper {
            max-width: none !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
    }

    /* =========================================================
       Boucle Workspace — two sibling cards (mockup boucle.php)
       The workspace parent is NEUTRAL (no card). Card styles live
       on the two children: thread panel (left) + side panel (right).
       ========================================================= */
    .chatloop-workspace {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;            /* mobile: single column */
        flex-direction: column;
        position: relative;
    }

    /* Left card: ChatLoop thread + composer (cream, calm) */
    .chatloop-thread-panel {
        min-height: 0;
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        background: linear-gradient(180deg, #FBF9F3, #FCFAF5);
    }
    .dark .chatloop-thread-panel { background: #111827; }

    /* Right card: active tool panel (white) — mobile overlay by default */
    .chatloop-side-panel {
        display: flex;
        flex-direction: column;
        background: #fff;
        position: absolute;
        inset: 0;
        z-index: 20;
        box-shadow: 0 24px 56px -18px rgba(20, 24, 60, .45);
    }
    .dark .chatloop-side-panel { background: #0b1220; }

    .chatloop-splitter { display: none; }

    @media (min-width: 1024px) {
        .chatloop-workspace {
            display: grid;
            /* grid-template-columns is set inline via gridStyle() (Alpine) */
            grid-template-columns: 1fr 0px 0px;
            align-items: stretch;
            padding: 4px 20px 20px;
        }
        /* Card styling on each child */
        .chatloop-thread-panel,
        .chatloop-side-panel {
            border: 1px solid rgb(229 231 235);
            border-radius: 24px;
            box-shadow: 0 1px 2px rgba(20, 24, 60, .05), 0 22px 50px -34px rgba(20, 24, 60, .34);
            overflow: hidden;
        }
        .dark .chatloop-thread-panel,
        .dark .chatloop-side-panel { border-color: rgb(55 65 81); }
        /* Side panel becomes a real grid cell (no longer an overlay) */
        .chatloop-side-panel {
            position: relative;
            inset: auto;
            z-index: auto;
            grid-column: 3;
        }
        .chatloop-thread-panel { grid-column: 1; }
        /* Vertical splitter in the middle track */
        .chatloop-splitter {
            grid-column: 2;
            display: flex;
            align-self: stretch;
            align-items: center;
            justify-content: center;
            cursor: col-resize;
            background: transparent;
            border: 0;
            touch-action: none;
        }
        .chatloop-splitter::before {
            content: "";
            width: 3px;
            height: 48px;
            border-radius: 999px;
            background: rgb(209 213 219);
            transition: background .15s, height .15s;
        }
        .dark .chatloop-splitter::before { background: rgb(75 85 99); }
        .chatloop-splitter:hover::before,
        [data-resizing="true"] .chatloop-splitter::before { background: rgb(139 92 246); height: 76px; }
        [data-resizing="true"] { cursor: col-resize; user-select: none; }
    }
</style>
@endpush

<x-app-layout :title="$currentLoop->name">
    <x-page-container width="none" class="loops-show-wrapper">
        <div
            x-data="{
                activeCard: null,
                wsWidth: 50,
                focus: 'none',
                resizing: false,
                openCard(card) { this.focus = 'none'; this.activeCard = this.activeCard === card ? null : card },
                closeCard() { this.activeCard = null; this.focus = 'none' },
                toggleChatFocus() { this.focus = this.focus === 'chat' ? 'none' : 'chat' },
                toggleToolFocus() { this.focus = this.focus === 'tool' ? 'none' : 'tool' },
                /* Desktop grid: expand isolates the focused card (the other disappears). */
                gridStyle() {
                    if (!this.activeCard || this.focus === 'chat') return 'grid-template-columns: 1fr 0px 0px';
                    if (this.focus === 'tool') return 'grid-template-columns: 0px 0px 1fr';
                    return 'grid-template-columns: minmax(0, 1fr) 14px ' + this.wsWidth + '%';
                },
                startResize(ev) {
                    if (!window.matchMedia('(min-width: 1024px)').matches) return;
                    ev.preventDefault();
                    this.focus = 'none';
                    this.resizing = true;
                    const panes = this.$refs.panes;
                    const move = (e) => {
                        const r = panes.getBoundingClientRect();
                        const pct = (r.right - e.clientX) / r.width * 100;
                        this.wsWidth = Math.min(64, Math.max(24, Math.round(pct)));
                    };
                    const up = () => {
                        this.resizing = false;
                        window.removeEventListener('pointermove', move);
                        window.removeEventListener('pointerup', up);
                    };
                    window.addEventListener('pointermove', move);
                    window.addEventListener('pointerup', up);
                }
            }"
            x-effect="document.body.style.overflow = activeCard && window.matchMedia('(max-width: 1023px)').matches ? 'hidden' : ''"
            @keydown.escape.window="closeCard()"
            x-bind:data-resizing="resizing ? 'true' : 'false'"
            class="loops-show-container h-dvh flex flex-col bg-gray-100 dark:bg-gray-950"
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

            </div>
            @can('update', $currentLoop)
                <a href="{{ $_loopRoute('edit', ['loop' => $currentLoop]) }}"
                   class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                   aria-label="{{ __('loops.edit') }}" title="{{ __('loops.edit') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/>
                    </svg>
                </a>
            @endcan
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

        {{-- The workspace tool bar ("Lancer" + tools) is the INTERNAL header of the
             left ChatLoop card — see the .chatloop-thread-panel below — not a global
             strip above the workspace. --}}

        {{-- Workspace: neutral parent, two sibling cards (chat | splitter | side) --}}
        <section
            class="chatloop-workspace"
            x-ref="panes"
            data-loop-workspace-panes
            x-bind:data-has-card="activeCard ? 'true' : 'false'"
            x-bind:style="gridStyle()"
        >
            <div
                x-show="focus !== 'tool'"
                x-bind:data-card-active="activeCard ? 'true' : 'false'"
                class="chatloop-thread-panel"
                data-loop-workspace-chat
            >
                @if($workspaceCards->isNotEmpty())
                    <div class="flex-shrink-0 border-b border-gray-200 bg-white/90 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/90 sm:px-4">
                        @include('loops.partials.chat-tools')
                    </div>
                @endif
                @livewire('loop-chat', ['loop' => $currentLoop], key('loop-chat-'.$currentLoop->id))
            </div>

            @if($workspaceCards->isNotEmpty())
                {{-- Resizable vertical splitter (desktop only, when a panel is open) --}}
                <button
                    type="button"
                    class="chatloop-splitter"
                    x-show="activeCard && focus === 'none'"
                    x-cloak
                    x-on:pointerdown="startResize($event)"
                    x-on:dblclick="wsWidth = 50"
                    aria-label="{{ __('loops.cards_panel_resize') }}"
                    title="{{ __('loops.cards_panel_resize') }}"
                ></button>

                <aside
                    x-show="activeCard && focus !== 'chat'"
                    x-cloak
                    x-transition.opacity.duration.150ms
                    x-on:keydown.escape.window="closeCard()"
                    class="chatloop-side-panel"
                    aria-label="{{ __('loops.cards_bar_label') }}"
                    data-loop-workspace-panel
                >
                    <div class="flex min-h-0 flex-1 flex-col">
                        {{-- Side card header. Desktop = docked card (title + expand only).
                             Mobile = full-screen panel (back + close). --}}
                        <div class="flex shrink-0 items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            {{-- Mobile only: back to chat --}}
                            <button
                                type="button"
                                x-on:click="closeCard()"
                                class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-violet-200 hover:text-violet-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-violet-700 dark:hover:text-violet-200 lg:hidden"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                {{ __('loops.cards_panel_back_to_chat') }}
                            </button>

                            {{-- Desktop: active tool title (docked card) --}}
                            <div class="hidden min-w-0 flex-1 lg:block">
                                @foreach($workspaceCards as $card)
                                    <p x-show="activeCard === @js($card['key'])" x-cloak class="truncate text-base font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ __($card['label_key']) }}</p>
                                @endforeach
                            </div>

                            {{-- Expand / restore (desktop only) --}}
                            <button
                                type="button"
                                x-on:click="toggleToolFocus()"
                                x-bind:aria-pressed="focus === 'tool'"
                                class="hidden h-9 w-9 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200 lg:inline-flex"
                                aria-label="{{ __('loops.cards_panel_expand') }}"
                                title="{{ __('loops.cards_panel_expand') }}"
                            >
                                <svg x-show="focus !== 'tool'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5-5-5m5 5v-4m0 4h-4"/></svg>
                                <svg x-show="focus === 'tool'" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V5m0 4H5m4 0L4 4m11 5h4m-4 0V5m0 4 5-5M9 15v4m0-4H5m4 0-5 5m11-5h4m-4 0v4m0-4 5 5"/></svg>
                            </button>

                            {{-- Mobile only: close --}}
                            <button
                                type="button"
                                x-on:click="closeCard()"
                                class="ml-auto inline-flex h-9 w-9 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200 lg:hidden"
                                aria-label="{{ __('loops.cards_panel_close') }}"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
                            @foreach($workspaceCards as $card)
                                <section x-show="activeCard === @js($card['key'])" x-cloak class="space-y-5">
                                    @if($card['key'] === 'core.ai_summary')
                                        <livewire:loop-ai-summary-card :loop="$currentLoop" :key="'loop-ai-summary-'.$currentLoop->id" lazy />
                                    @elseif($card['key'] === 'core.manifesto')
                                        {{-- Manifesto — designated primary BlogPost (lazy Livewire card) --}}
                                        <livewire:loop-manifesto-card :loop="$currentLoop" :key="'loop-manifesto-'.$currentLoop->id" lazy />
                                    @elseif($card['key'] === 'core.roadmap')
                                        {{-- Roadmap — persistent (loop_roadmap_items), lazy Livewire card --}}
                                        <livewire:loop-roadmap-card :loop="$currentLoop" :key="'loop-roadmap-'.$currentLoop->id" lazy />
                                    @elseif($card['key'] === 'core.members')
                                        {{-- Members list + email invitation --}}
                                        <div class="space-y-4">
                                            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __($card['label_key']) }}</p>
                                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('loops.members_count', ['count' => $loopMembers->count()]) }}</p>
                                            </div>

                                            @if($loopMembers->isEmpty())
                                                <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                                    {{ __($card['empty_title_key']) }}
                                                </div>
                                            @else
                                                <ul class="space-y-2">
                                                    @foreach($loopMembers as $member)
                                                        @php
                                                            $mUser = $member->user;
                                                            $mAvatar = $mUser?->isDisplayableIn(currentOrganization()) ? $mUser?->avatar_url : null;
                                                            $roleLabel = __('loops.members_role_'.($member->role ?: 'member'));
                                                        @endphp
                                                        <li class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900">
                                                            @if($mAvatar)
                                                                <img src="{{ $mAvatar }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover">
                                                            @else
                                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-600 dark:bg-violet-500/20 dark:text-violet-300">{{ mb_strtoupper(mb_substr($mUser?->publicDisplayName() ?? '?', 0, 1)) }}</span>
                                                            @endif
                                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $mUser?->publicDisplayName() ?? 'BouclePro' }}</span>
                                                            <span class="shrink-0 rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">{{ $roleLabel }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                                                <p class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                                                    <svg class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                                                    {{ __('loops.members_invite_title') }}
                                                </p>
                                                <form method="POST" action="{{ $inviteAction }}" class="mt-3 space-y-2">
                                                    @csrf
                                                    <input type="email" name="recipient_email" required placeholder="{{ __('loops.members_invite_email_placeholder') }}"
                                                           class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                                                    <input type="text" name="recipient_name" maxlength="255" placeholder="{{ __('loops.members_invite_name_placeholder') }}"
                                                           class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                                                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                                                        {{ __('loops.members_invite_submit') }}
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    @endif
                                </section>
                            @endforeach
                        </div>
                    </div>
                </aside>
            @endif
        </section>

        <x-conversation.image-lightbox key="loop-chat" />

        {{-- Bottom strip: join CTA (guests) / help-request modal holder (members) --}}
        <div class="flex-shrink-0">
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
                {{-- Help-request modal. Trigger lives above the composer (in loop-chat) and
                     opens this modal via the `bp-open-help-request` window event. --}}
                <div x-data="{ open: @js($analysis ? true : false) }" @bp-open-help-request.window="open = true">
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
