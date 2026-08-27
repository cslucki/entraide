@php
    $currentLoop = $loop;
    // TASK-1211 : deposee par le controleur (HelpRequestHandoff), pas par un
    // flash de session — le poll de ChatLoop l'aurait consommee avant l'ecran.
    $analysis = $helpRequestAnalysis ?? null;
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
    $loopMembers = $currentLoop->members->where('status', 'active')->sortBy(fn ($m) => match ($m->role) {
        'owner' => 0, 'moderator' => 1, default => 2,
    });
    $inviteAction = ($_org && request()->routeIs('organization.*') && Route::has('organization.points.invitation.send'))
        ? route('organization.points.invitation.send', ['organization' => $_org])
        : route('points.invitation.send');
    $loopInvitationAction = ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.invitations.store'))
        ? route('organization.loops.invitations.store', ['organization' => $_org, 'loop' => $currentLoop])
        : route('loops.invitations.store', $currentLoop);
    // Les panneaux rendus a droite : la grille, plus le cadre permanent et les
    // actions ChatLoop, dont les boutons d'ouverture vivent ailleurs depuis
    // TASK-1090. La mecanique du panneau ne change pas — seuls les points
    // d'entree ont demenage.
    $panelCards = collect($workspaceCards)
        ->concat($frameCards ?? collect())
        ->concat($chatActionCards ?? collect())
        ->unique('key')
        ->values();
    // $workspaceCards is resolved by LoopController::show(). It used to be built
    // here from the global catalogue filtered on `default_enabled`, so every Loop
    // showed every card whatever its own composition — and three of them opened
    // on an empty panel because `requires_card` denied the read. The view no
    // longer decides what a Loop contains.
    //
    // Order comes from the catalogue (`order`), applied by activeCardsFor():
    // `loop_cards` carries no position column, so there is no per-Loop ordering
    // to preserve.
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
        /* TASK-1231 : le FAB « + » est masque ici (au-dessus) et la rangee des
           actions IA + le composeur occupent le bas de l'ecran : le FAB
           BouclePro IA remonte au-dessus de cette rangee, sans la couvrir. */
        body:has(.loops-show-container) [data-ai-fab-toggle] {
            bottom: 14rem !important;
        }
        body:has(.loops-show-container) [data-ai-fab-panel] {
            bottom: 17.5rem !important;
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
    /* Les couleurs viennent des jetons de theme, comme le reste du site. En dur,
       cet ecran restait beige quel que soit le theme choisi — le seul du produit
       a ne pas suivre. */
    .chatloop-thread-panel {
        min-height: 0;
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        /* --bp-panel, pas --bp-surface-soft : dans le theme zen ce dernier vaut
           la couleur de bordure (#DDE3F0), ce qui donnait un panneau bleu-gris
           sur une page vert d'eau. Les deux panneaux sont des cartes posees sur
           la page teintee — c'est le motif du reste du produit. */
        background: var(--bp-panel);
    }

    /* Right card: active tool panel (white) — mobile overlay by default */
    .chatloop-side-panel {
        display: flex;
        flex-direction: column;
        background: var(--bp-panel);
        position: absolute;
        inset: 0;
        z-index: 20;
        box-shadow: 0 24px 56px -18px rgba(20, 24, 60, .45);
    }


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
            border: 1px solid var(--bp-border);
            border-radius: 24px;
            box-shadow: 0 1px 2px rgba(20, 24, 60, .05), 0 22px 50px -34px rgba(20, 24, 60, .34);
            overflow: hidden;
        }

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
            {{-- Ouvrir une Card depuis ailleurs — le message ChatLoop qui annonce
                 un Sondage, par exemple. Le nom de la Card est compare a celles
                 que le workspace rend deja : un evenement portant une cle
                 inconnue n'ouvre rien. --}}
            @bp-open-loop-card.window="
                if ($event.detail?.card && @js($panelCards->pluck('key')).includes($event.detail.card)) {
                    activeCard = $event.detail.card; focus = 'none';
                }
            "
            x-bind:data-resizing="resizing ? 'true' : 'false'"
            class="loops-show-container h-dvh flex flex-col bg-[var(--bp-page)]"
            data-loop-workspace-shell
        >

        {{-- Topbar --}}
        <div class="flex flex-nowrap items-center gap-2 border-b border-[var(--bp-border)] px-3 py-2.5 flex-shrink-0 sm:gap-3 sm:px-4">
            @php $backHome = app()->bound('current_organization') && app('current_organization')->isMonoLoop(); @endphp
            <a href="{{ $backHome ? route('home') : $_loopRoute('index') }}"
               class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]"
               aria-label="{{ $backHome ? __('loops.back_home') : __('loops.back_to_loops') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div class="min-w-0 flex-1 sm:flex sm:items-center sm:gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-start gap-2">
                        <h1 class="truncate text-base font-semibold text-[var(--bp-text)] sm:text-lg">{{ $currentLoop->name }}</h1>
                        <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full border px-1 py-px text-[8px] font-semibold uppercase tracking-wide {{ $currentLoop->isPublic() ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' }}">
                            {{ $currentLoop->isPublic() ? __('loops.visibility_public') : __('loops.visibility_private') }}
                        </span>
                    </div>
                    @if($currentLoop->description)
                        <p class="truncate text-xs text-[var(--bp-muted)]">{{ $currentLoop->description }}</p>
                    @endif
                </div>

            </div>
            @include('loops.partials.header-actions')
        </div>

        @if($canArchiveLoop ?? false)
            @include('loops.partials.archive-modal', ['impact' => $archiveImpact ?? []])
        @endif

        {{-- Session messages --}}
        @if(session('success') && session('success') !== 'Message envoyé.')
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="flex-shrink-0 bg-green-50 dark:bg-green-900/20 border-b border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-2 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            {{-- TASK-1231 (lot 0) : un refus de la garde IA reste visible plus
                 longtemps et porte « Voir les offres » quand le credit personnel
                 est epuise (meme regle que les surfaces 1229). --}}
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, {{ session('ai_refusal_code') ? 8000 : 4000 }})"
                 class="flex-shrink-0 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-2 text-sm"
                 @if(session('ai_refusal_code')) data-ai-refusal-code="{{ session('ai_refusal_code') }}" @endif>
                {{ session('error') }}
                @if(session('ai_offers_url'))
                    <a href="{{ session('ai_offers_url') }}" class="ml-2 font-semibold underline" data-ai-offers-link>{{ __('ai.credit_see_offers') }}</a>
                @endif
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

        @if($currentLoop->isArchived())
            {{-- Une Boucle archivee reste ouverte a ceux qui pouvaient la voir.
                 Le bandeau dit ce qui a change et ce qui n'a pas change : plus de
                 contribution, mais rien de perdu. Sans lui, les boutons refuses
                 par le serveur passeraient pour des pannes. --}}
            <div class="mb-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-800/60 dark:bg-amber-900/20">
                <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                    {{ __('loops.archive_banner_title') }}
                    @if($currentLoop->archived_at)
                        <span class="text-xs font-normal text-amber-700 dark:text-amber-300">
                            {{ __('loops.archived_since', ['date' => $currentLoop->archived_at->isoFormat('LL')]) }}
                        </span>
                    @endif
                </p>
                <p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-200/90">{{ __('loops.archive_banner_body') }}</p>
            </div>
        @endif

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
                @if($workspaceCards->isNotEmpty() || ($chatActionCards ?? collect())->isNotEmpty())
                    <div class="flex-shrink-0 border-b border-gray-200 bg-white/90 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/90 sm:px-4">
                        @include('loops.partials.chat-tools')
                    </div>
                @endif
                @livewire('loop-chat', ['loop' => $currentLoop], key('loop-chat-'.$currentLoop->id))
            </div>

            @if($panelCards->isNotEmpty())
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
                                @foreach($panelCards as $card)
                                    <p x-show="activeCard === @js($card['key'])" x-cloak class="truncate text-base font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ app(\App\Support\Loops\LoopCardRegistry::class)->labelFor($currentLoop, $card['key']) }}</p>
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
                            @foreach($panelCards as $card)
                                <section x-show="activeCard === @js($card['key'])" x-cloak class="space-y-5">
                                    {{-- Rendu pilote par le registre : plus aucune condition sur
                                         une cle de Card ici. Une Card sans composant ni vue ne
                                         rend rien plutot que d'ouvrir sur le vide, et aucune
                                         chaine fournie par un utilisateur n'atteint Livewire —
                                         le nom vient du catalogue, verifie par le registre.

                                         Le registre est interroge directement, sans passer par
                                         une variable locale : la forme en ligne de la directive
                                         PHP compile mal quand l'expression contient des
                                         crochets, et laissait une balise ouverte jamais
                                         refermee qui desarticulait tout le fichier a partir de
                                         ce point. Le nom de cette directive n'est pas ecrit ici
                                         non plus — les blocs bruts sont extraits avant que les
                                         commentaires ne soient retires, donc le citer suffisait
                                         a reproduire le defaut. --}}
                                    @if($cardRegistry->componentFor($card['key']))
                                        @livewire($cardRegistry->componentFor($card['key']), ['loop' => $currentLoop], key($card['key'].'-'.$currentLoop->id))
                                    @elseif($cardRegistry->viewFor($card['key']))
                                        @include($cardRegistry->viewFor($card['key']), ['card' => $card])
                                    @endif
                                </section>
                            @endforeach
                        </div>
                    </div>
                </aside>
            @endif
        </section>

        <x-conversation.image-lightbox key="loop-chat" />

        @if($isMember)
            {{-- TASK-1213 : « Consulter les Dossiers » — reponse documentaire sourcee,
                 read-only. Ouvert par l'evenement `bp-open-knowledge` (bouton dans
                 loop-chat). Requete JSON, aucune ecriture, aucune session. --}}
            <div x-data="{
                    open: false,
                    question: '',
                    loading: false,
                    error: null,
                    errorCode: null,
                    offersUrl: null,
                    result: null,
                    endpoint: @js($_loopRoute('knowledge.ask', ['loop' => $currentLoop])),
                    reset() { this.error = null; this.errorCode = null; this.offersUrl = null; this.result = null; },
                    async ask() {
                        const q = this.question.trim();
                        if (q.length < 3 || this.loading) return;
                        this.loading = true; this.reset();
                        try {
                            const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
                            const response = await fetch(this.endpoint, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                                body: JSON.stringify({ question: q })
                            });
                            const data = await response.json();
                            if (!response.ok) { this.error = data.error || (data.errors && Object.values(data.errors).flat()[0]) || @js(__('loops.knowledge_error')); this.errorCode = data.code || null; this.offersUrl = data.offers_url || null; return; }
                            this.result = data;
                        } catch (e) {
                            this.error = @js(__('loops.knowledge_error'));
                        } finally {
                            this.loading = false;
                        }
                    }
                }"
                @bp-open-knowledge.window="open = true; $nextTick(() => $refs.knowledgeQuestion?.focus())"
                data-knowledge-modal>
                <template x-teleport="body">
                    <div x-show="open" x-cloak
                        class="fixed inset-0 z-50 flex items-center justify-center"
                        x-effect="document.body.style.overflow = open ? 'hidden' : ''"
                        @keydown.escape.window="open = false">
                        <div x-show="open" @click="open = false" class="fixed inset-0 bg-black/50 transition-opacity"></div>
                        <div x-show="open" @click.away="open = false"
                            class="relative w-full max-w-lg bg-white dark:bg-gray-800 rounded-2xl shadow-xl flex flex-col max-h-[85vh] mx-3" data-knowledge-dialog>
                            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.knowledge_title') }}</h3>
                                <button type="button" @click="open = false" class="p-1 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('loops.knowledge_close') }}">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="overflow-y-auto px-4 py-3 min-h-0 flex-1 space-y-3">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('loops.knowledge_intro') }}</p>
                                <form @submit.prevent="ask()" class="space-y-2">
                                    <label for="knowledge-question" class="sr-only">{{ __('loops.knowledge_title') }}</label>
                                    <textarea id="knowledge-question" x-ref="knowledgeQuestion" x-model="question" rows="3" maxlength="500" minlength="3" required
                                        placeholder="{{ __('loops.knowledge_placeholder') }}"
                                        class="w-full resize-none px-3.5 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-sky-400 focus:border-transparent"></textarea>
                                    <button type="submit" :disabled="loading || question.trim().length < 3"
                                        class="w-full px-4 py-2.5 bg-sky-600 hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                        <span x-show="!loading">{{ __('loops.knowledge_ask') }}</span>
                                        <span x-show="loading">{{ __('loops.knowledge_asking') }}</span>
                                    </button>
                                </form>

                                {{-- TASK-1229 : le refus porte son code (credit utilisateur epuise / budget
                                     Organization / IA non configuree) ; seul le credit propose « Voir les offres ». --}}
                                <div x-show="error" x-cloak class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200" data-knowledge-error :data-ai-refusal-code="errorCode || null">
                                    <span x-text="error"></span>
                                    <div x-show="errorCode === 'user_credit_exhausted' && offersUrl" x-cloak class="mt-2">
                                        <a :href="offersUrl" class="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700" data-ai-credit-offers-link>{{ __('ai.credit_see_offers') }}</a>
                                    </div>
                                </div>

                                <template x-if="result">
                                    <div class="space-y-3" data-knowledge-result>
                                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900/40">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300 mb-1">{{ __('loops.knowledge_answer_title') }}</p>
                                            <p class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-line" data-knowledge-answer x-text="result.answer"></p>
                                        </div>
                                        {{-- TASK-1229 : alerte de seuil, calme et informative, juste sous la reponse — l'action n'a pas ete bloquee. --}}
                                        <template x-if="result.credit && result.credit.alert">
                                            <p class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-800/50 dark:bg-sky-900/20 dark:text-sky-200" data-ai-credit-alert
                                               x-text="@js(__('ai.credit_alert_remaining')).replace(':remaining', result.credit.remaining).replace(':used', result.credit.used).replace(':quota', result.credit.quota)"></p>
                                        </template>
                                        {{-- TASK-1309 : « Sources utilisées » ne montre QUE les
                                             sources reellement citees (result.sources, desormais
                                             les seules citations validees). Ce qui a ete consulte
                                             sans etayer aucune affirmation garde sa place ici,
                                             mais sous son vrai titre — « Sources consultées » —
                                             jamais presente comme un appui. --}}
                                        <div x-show="(result.grounded ? result.sources : result.consulted || []).length" data-knowledge-sources>
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1" x-text="result.grounded ? @js(__('loops.knowledge_sources_title')) : @js(__('loops.knowledge_consulted_title'))"></p>
                                            <ul class="space-y-2">
                                                <template x-for="source in (result.grounded ? result.sources : result.consulted || [])" :key="source.ref">
                                                    <li class="rounded-lg border border-gray-200 dark:border-gray-700 p-2.5 text-xs" data-knowledge-source>
                                                        <div class="flex items-start justify-between gap-2">
                                                            <div class="min-w-0">
                                                                <span class="font-mono text-[10px] text-sky-700 dark:text-sky-300" x-text="'[' + source.ref + ']'"></span>
                                                                <span class="font-semibold text-gray-900 dark:text-gray-100" x-text="source.title"></span>
                                                                <span class="text-gray-500 dark:text-gray-400" x-text="' · ' + source.dossier_name"></span>
                                                            </div>
                                                            <a x-show="source.url" :href="source.url" target="_blank" rel="noopener" class="flex-shrink-0 text-sky-700 dark:text-sky-300 hover:underline">{{ __('loops.knowledge_open_source') }}</a>
                                                        </div>
                                                        <p class="mt-1 text-gray-600 dark:text-gray-300 italic" x-text="source.excerpt"></p>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('loops.knowledge_disclaimer') }}</p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        @endif

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
                                            $originalPhrase = $analysis['original_phrase'] ?? ($helpRequestIntention ?? '');
                                            $fallbackNeedEmpty = $needsFallback && empty($analysis['need']) && $originalPhrase;
                                            $needValue = $fallbackNeedEmpty ? $originalPhrase : ($analysis['need'] ?? '');
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

                                        <form method="POST" action="{{ $_loopRoute('help-request.continue', ['loop' => $currentLoop]) }}" class="space-y-3">
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
                                            {{-- TASK-1211 : la categorie suggeree part avec le titre et la
                                                 description vers le formulaire canonique, ou l'humain la
                                                 garde ou la change. Revalidee cote serveur a chaque etape. --}}
                                            @php
                                                $suggestedCategory = $analysis['suggested_category'] ?? null;
                                                $suggestedCategoryId = is_array($suggestedCategory) ? ($suggestedCategory['id'] ?? null) : null;
                                            @endphp
                                            @if($suggestedCategoryId)
                                                <input type="hidden" name="suggested_category_id" value="{{ $suggestedCategoryId }}">
                                                <p class="text-xs text-indigo-600 dark:text-indigo-300">
                                                    <span class="font-semibold">{{ __('loops.help_request_suggested_category') }}</span> · {{ $suggestedCategory['label'] ?? '' }}
                                                </p>
                                            @endif
                                            {{-- TASK-1210 : la destination. L'IA propose, l'humain choisit.
                                                 Le select n'offre que des Boucles dont il est membre actif, et
                                                 le serveur revalide de toute facon a la publication. --}}
                                            @php
                                                $suggested = $analysis['suggested_loop'] ?? null;
                                                $suggestedId = is_array($suggested) ? ($suggested['id'] ?? null) : null;
                                                $selectedLoopId = old('relay_loop_id', $suggestedId ?? $currentLoop->id);
                                            @endphp
                                            <div>
                                                <label for="hr-loop" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('loops.help_request_choose_loop') }}</label>
                                                <select name="relay_loop_id" id="hr-loop"
                                                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                                    <option value="" @selected($selectedLoopId === null || $selectedLoopId === '')>{{ __('loops.help_request_no_relay_loop') }}</option>
                                                    @foreach(($publishableLoops ?? collect()) as $candidate)
                                                        <option value="{{ $candidate->id }}" @selected($selectedLoopId === $candidate->id)>{{ $candidate->name }}</option>
                                                    @endforeach
                                                </select>
                                                @if($suggestedId && !empty($suggested['reason']))
                                                    <p class="mt-1.5 text-xs text-indigo-600 dark:text-indigo-300">
                                                        <span class="font-semibold">{{ __('loops.help_request_suggested_loop') }}</span> · {{ $suggested['reason'] }}
                                                    </p>
                                                @endif
                                                @error('relay_loop_id')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
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
                                                    {{ __('loops.help_request_continue_cta') }}
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
