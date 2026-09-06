@auth
@php
    // TASK-1231 : FAB « BouclePro IA » — point d'entree unique et contextuel.
    // Le contexte (actions de la page + credit) est calcule cote serveur par
    // AiFabContext, une lecture par requete ; le composant ne devine rien en
    // lisant le DOM et n'appelle jamais un provider : il ouvre des surfaces
    // qui existent (evenements window) ou suit un lien. Rendu dans le seul
    // layout membre (`layouts.app`), jamais guest / admin / org-admin.
    $fab = app(\App\Support\Ai\AiFabContext::class)->forRequest(request(), auth()->user());
@endphp
@if($fab)
<div x-data="{
        open: false,
        ctx: @js($fab),
        toggle() { this.open = ! this.open; },
        close() { this.open = false; },
        run(action) {
            this.close();
            if (action.kind === 'event') {
                window.dispatchEvent(new CustomEvent(action.event, { detail: action.detail || {} }));
            } else if (action.kind === 'link' && action.url) {
                window.location.assign(action.url);
            }
        },
    }"
    @keydown.escape.window="close()"
    @click.outside="close()"
    data-ai-fab
    :data-ai-fab-open="open ? 'true' : 'false'"
    data-ai-fab-page="{{ $fab['page'] }}"
    data-ai-fab-tone="{{ $fab['credit_tone'] }}"
    class="print:hidden">

    {{-- Bouton flottant. Mobile : au-dessus du FAB « + » (bottom-20) et de la
         barre basse ; desktop : au-dessus des toasts (bottom-5). --}}
    <button type="button"
            @click="toggle()"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="ai-fab-panel"
            aria-label="{{ __('ai.fab_open') }}"
            title="{{ __('ai.fab_label') }}"
            data-ai-fab-toggle
            class="fixed bottom-36 right-4 md:bottom-24 md:right-6 z-40 inline-flex items-center gap-2 rounded-full bg-gradient-to-br from-violet-600 to-indigo-600 text-white shadow-lg shadow-indigo-900/20 ring-1 ring-white/20 hover:from-violet-500 hover:to-indigo-500 active:scale-95 transition h-12 w-12 md:h-auto md:w-auto md:px-4 md:py-2.5 justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-indigo-500">
        {{-- Symbole de marque en monochrome blanc translucide : essai visuel,
             directement sur l'aplat violet/indigo du bouton, sans pastille. --}}
        <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 512 512" fill="none" stroke="white" stroke-width="46" stroke-linecap="round" stroke-opacity="0.92" aria-hidden="true">
            <path d="M 213.1 142 A 56 56 0 1 1 298.9 142"/>
            <path d="M 306.28 145.05 A 56 56 0 1 1 366.95 205.72"/>
            <path d="M 370 213.1 A 56 56 0 1 1 370 298.9"/>
            <path d="M 366.95 306.28 A 56 56 0 1 1 306.28 366.95"/>
            <path d="M 298.9 370 A 56 56 0 1 1 213.1 370"/>
            <path d="M 205.72 366.95 A 56 56 0 1 1 145.05 306.28"/>
            <path d="M 142 298.9 A 56 56 0 1 1 142 213.1"/>
            <path d="M 145.05 205.72 A 56 56 0 1 1 205.72 145.05"/>
            <path d="M 213.1 142 A 56 56 0 0 1 213.1 70"/>
        </svg>
        <span class="hidden md:inline text-sm font-semibold">{{ __('ai.fab_label') }}</span>
        {{-- Pastille d'etat du credit : ambre a l'alerte, rouge au plafond. --}}
        @if($fab['credit_tone'] !== 'ok')
            <span class="absolute -top-0.5 -right-0.5 h-3 w-3 rounded-full ring-2 ring-white dark:ring-gray-900 {{ $fab['credit_tone'] === 'exhausted' ? 'bg-rose-500' : 'bg-amber-400' }}" data-ai-fab-badge aria-hidden="true"></span>
        @endif
    </button>

    {{-- Panneau contextuel. --}}
    {{-- TASK-1244.BUG : pas de x-transition ici. Alpine fait alors dependre le
         basculement de `display` d'une sequence requestAnimationFrame, qui
         reste bloquee tant que `document.hidden` est vrai (onglet en arriere-
         plan/occlus) — le panneau restait display:none malgre open=true.
         Meme motif (x-show/x-cloak sans transition) que les deux autres
         modaux de loops/show.blade.php. --}}
    <div x-show="open" x-cloak
         id="ai-fab-panel"
         role="dialog"
         aria-modal="false"
         aria-labelledby="ai-fab-title"
         data-ai-fab-panel
         class="fixed bottom-52 right-4 md:bottom-40 md:right-6 z-40 w-[calc(100vw-2rem)] max-w-xs rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800 overflow-hidden">

        <div class="flex items-start justify-between gap-3 px-4 pt-4 pb-3 border-b border-gray-100 dark:border-gray-700">
            <div class="min-w-0">
                <p id="ai-fab-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.fab_title') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    {{-- TASK-1350 : le sous-titre dit OU l'on est, jamais que l'IA
                         serait inutile ici. « Aucune action propre a cette page »
                         ne signifie pas « BouclePro IA indisponible » : la
                         distinction est faite plus bas, a l'endroit des actions. --}}
                    @if($fab['page'] === 'loop') {{ __('ai.fab_subtitle_loop') }}
                    @elseif($fab['page'] === 'dossier') {{ __('ai.fab_subtitle_dossier') }}
                    @else {{ __('ai.fab_subtitle_other') }}
                    @endif
                </p>
            </div>
            <button type="button" @click="close()" class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-200 dark:hover:bg-gray-700" aria-label="{{ __('ai.fab_close') }}" data-ai-fab-close>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- TASK-1315 : la porte du Shell. Elle est la PREMIERE entree du
             panneau et reste offerte meme au plafond de credit : ouvrir le Shell
             ne consomme rien, et le fil deja ecrit doit rester relisible. Les
             actions historiques, elles, gardent exactement leur regime. --}}
        @if($fab['shell_enabled'])
            <div class="px-3 pt-3">
                <button type="button"
                        @click="close(); window.dispatchEvent(new CustomEvent('bp-open-ai-shell', { detail: {} }))"
                        data-ai-fab-shell
                        class="w-full text-left flex items-start gap-3 rounded-xl bg-gradient-to-br from-violet-600 to-indigo-600 px-3 py-2.5 text-white shadow-sm hover:from-violet-500 hover:to-indigo-500 transition">
                    <span class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-white/15">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12a8 8 0 0 1-8 8H7l-4 3v-5.5A8 8 0 1 1 21 12Z"/></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">{{ __('ai.fab_action_open_shell') }}</span>
                        <span class="block text-xs text-indigo-100">{{ __('ai.fab_action_open_shell_hint') }}</span>
                    </span>
                </button>
                {{-- OU l'on se trouve — la meme phrase que l'en-tete du Shell,
                     calculee par la meme autorite. Elle n'accorde aucun droit. --}}
                @if(filled($fab['page_context']['label'] ?? null))
                    <p class="mt-2 flex items-center gap-1.5 text-[11px] text-gray-500 dark:text-gray-400" data-ai-fab-page-context="{{ $fab['page_context']['kind'] }}">
                        <svg class="h-3 w-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.35 7-10a7 7 0 1 0-14 0c0 5.65 7 10 7 10Z"/><circle cx="12" cy="11" r="2.5"/></svg>
                        <span class="truncate">{{ $fab['page_context']['label'] }}</span>
                    </p>
                @endif
            </div>
        @endif

        @if($fab['credit']['exhausted'])
            {{-- Au plafond : les actions sont REMPLACEES par le refus, sans
                 rien appeler — le refus reel vit dans AiEconomicGuard. --}}
            <div class="px-4 py-3 space-y-3" data-ai-fab-refusal>
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm leading-5 text-rose-800 dark:border-rose-800/50 dark:bg-rose-900/20 dark:text-rose-200">
                    {{ $fab['refusal_message'] }}
                </div>
                @if($fab['offers_url'])
                    <a href="{{ $fab['offers_url'] }}" data-ai-fab-offers
                       class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        {{ __('ai.credit_see_offers') }}
                    </a>
                @endif
            </div>
        @elseif(count($fab['actions']) > 0)
            <ul class="py-1" data-ai-fab-actions>
                @foreach($fab['actions'] as $i => $action)
                    <li>
                        <button type="button"
                                @click="run(ctx.actions[{{ $i }}])"
                                data-ai-fab-action="{{ $action['key'] }}"
                                class="w-full text-left flex items-start gap-3 px-4 py-2.5 hover:bg-indigo-50/70 dark:hover:bg-gray-700/60 transition">
                            <span class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300">
                                @if($action['key'] === \App\Support\Ai\AiFabContext::ACTION_LOOP_ASK)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 5h.01M18 9h.01M6 4h.01"/></svg>
                                @elseif($action['key'] === \App\Support\Ai\AiFabContext::ACTION_LOOP_KNOWLEDGE)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                                @elseif($action['key'] === \App\Support\Ai\AiFabContext::ACTION_LOOP_SUMMARY)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h7"/></svg>
                                @elseif($action['key'] === \App\Support\Ai\AiFabContext::ACTION_HELP_REQUEST)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                @else
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z"/></svg>
                                @endif
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $action['label'] }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $action['hint'] }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif($fab['shell_enabled'])
            {{-- TASK-1350 — l'honnetete du panneau, en une phrase.
                 Cette page n'expose aucune action IA propre : on le DIT, et on
                 dit dans la meme phrase que la conversation, elle, reste
                 ouverte. C'est exactement la distinction que le produit doit
                 tenir — « pas d'action ici » n'est pas « pas d'IA ici ». --}}
            <p class="px-4 py-3 text-xs leading-5 text-gray-500 dark:text-gray-400" data-ai-fab-no-page-action>
                {{ __('ai.fab_no_page_action') }}
            </p>
        @endif

        {{-- Credit utilisateur : la seule chose chiffree que le FAB montre.
             Jamais le budget Organization, jamais un cout. --}}
        <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50/70 dark:bg-gray-900/40" data-ai-fab-credit>
            <div class="flex items-center justify-between gap-3">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.fab_credit_title') }}</span>
                <span class="text-xs font-semibold {{ $fab['credit_tone'] === 'exhausted' ? 'text-rose-700 dark:text-rose-300' : ($fab['credit_tone'] === 'alert' ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300') }}" data-ai-fab-credit-label>{{ $fab['credit_label'] }}</span>
            </div>
            @if(! $fab['credit']['unlimited'] && (int) $fab['credit']['quota'] > 0)
                <div class="mt-2 h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ (int) $fab['credit']['quota'] }}" aria-valuenow="{{ (int) $fab['credit']['used'] }}">
                    <div class="h-full rounded-full {{ $fab['credit_tone'] === 'exhausted' ? 'bg-rose-500' : ($fab['credit_tone'] === 'alert' ? 'bg-amber-400' : 'bg-emerald-500') }}" style="width: {{ min(100, (float) ($fab['credit']['percent'] ?? 0)) }}%"></div>
                </div>
                <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('ai.credit_used_of_quota', ['used' => (int) $fab['credit']['used'], 'quota' => (int) $fab['credit']['quota']]) }}</p>
            @endif
            @if($fab['credit_tone'] === 'alert')
                <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-300" data-ai-fab-alert>{{ __('ai.fab_credit_alert') }}</p>
            @endif
            <a href="{{ $fab['usage_url'] }}" data-ai-fab-usage class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-300">
                {{ __('ai.fab_usage_link') }}
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        </div>
    </div>
</div>
@endif
@endauth
