{{-- ChatLoop card internal header: "Lancer" + tool buttons + expand chat.
     Rendered INSIDE the left chat card (not as a global toolbar). Relies on the
     root Alpine scope (activeCard / openCard / toggleChatFocus / todos) and the
     $workspaceCards / $manifestoVersion variables from show.blade.

     L'icone et la teinte d'une Card viennent du composant x-loops.card-icon,
     seul endroit qui les declare. --}}
<div class="flex items-center gap-3 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    <span class="hidden shrink-0 items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-[0.04em] text-gray-400 dark:text-gray-500 sm:inline-flex" title="{{ __('loops.cards_bar_hint') }}">
        <svg class="h-4 w-4 text-violet-500 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 4V2m0 20v-2M9 4l1 1m4 14 1 1M4 9h2m14 6h2M4 15l1-1m14-4 1-1M6.5 17.5 17 7"/></svg>
        {{ __('loops.cards_bar_launch') }}
    </span>

    {{-- Les actions de conversation — le Résumé IA — sont remontées dans la
         barre de titre de la Boucle (loops/partials/header-actions). Les
         afficher aussi ici donnait deux boutons pour un seul geste. --}}

    <div class="flex min-w-0 flex-1 items-center gap-2">
        @foreach($workspaceCards as $card)
            <button
                type="button"
                x-on:click="openCard(@js($card['key']))"
                x-bind:aria-pressed="activeCard === @js($card['key'])"
                x-bind:class="activeCard === @js($card['key']) ? 'border-violet-300 bg-white ring-1 ring-violet-200 shadow-sm dark:border-violet-600/70 dark:bg-gray-800 dark:ring-violet-700/50' : 'border-gray-200 bg-white hover:border-violet-200 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-violet-700'"
                class="group inline-flex min-h-11 shrink-0 items-center gap-2.5 rounded-2xl border px-3 py-1.5 transition"
            >
                <x-loops.card-icon :icon="$card['icon'] ?? null" />
                <span class="text-sm font-bold tracking-tight text-gray-800 dark:text-gray-100">{{ __($card['label_key']) }}</span>
                @if($card['key'] === 'core.members')
                    <span class="ml-0.5 rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ $loopMembers->count() }}</span>
                    @if(($canManageJoinRequests ?? false) && $pendingJoinRequests->isNotEmpty())
                        <span class="ml-0.5 rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white" title="{{ __('loops.join_requests_title') }}">{{ $pendingJoinRequests->count() }}</span>
                    @endif
                @endif
            </button>
        @endforeach
    </div>

    {{-- Expand chat (focus) — desktop only, when a panel is open --}}
    <button
        type="button"
        x-show="activeCard"
        x-cloak
        x-on:click="toggleChatFocus()"
        x-bind:aria-pressed="focus === 'chat'"
        class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200 lg:inline-flex"
        aria-label="{{ __('loops.cards_bar_expand_chat') }}"
        title="{{ __('loops.cards_bar_expand_chat') }}"
    >
        <svg x-show="focus !== 'chat'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5-5-5m5 5v-4m0 4h-4"/></svg>
        <svg x-show="focus === 'chat'" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V5m0 4H5m4 0L4 4m11 5h4m-4 0V5m0 4 5-5M9 15v4m0-4H5m4 0-5 5m11-5h4m-4 0v4m0-4 5 5"/></svg>
    </button>
</div>
