{{-- ChatLoop card internal header: tool buttons + expand chat.
     Rendered INSIDE the left chat card (not as a global toolbar). Relies on the
     root Alpine scope (activeCard / openCard / toggleChatFocus / todos) and the
     $workspaceCards / $manifestoVersion variables from show.blade.

     L'icone et la teinte d'une Card viennent du composant x-loops.card-icon,
     seul endroit qui les declare. --}}
{{-- Deux niveaux : la barre qui defile, et — hors d'elle — le depliant
     « Autres outils ». `overflow-x-auto` cree un contexte de rognage : un
     panneau absolu place dedans est coupe (constate en recette TASK-1124). --}}
<div class="flex items-center gap-3">
<div class="flex min-w-0 flex-1 items-center gap-3 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

    {{-- Les actions de conversation — le Résumé IA — sont remontées dans la
         barre de titre de la Boucle (loops/partials/header-actions). Les
         afficher aussi ici donnait deux boutons pour un seul geste. --}}

    <div class="flex min-w-0 flex-1 items-center gap-2">
        {{-- Les outils mis en avant. Les autres restent accessibles juste
             après, sous « Autres outils » : avant TASK-1124 la barre était
             coupée à trois et la 4e Card active était introuvable. --}}
        @foreach(($toolbarCards ?? $primaryCards ?? $workspaceCards) as $card)
            <button
                type="button"
                x-on:click="openCard(@js($card['key']))"
                x-bind:aria-pressed="activeCard === @js($card['key'])"
                x-bind:class="activeCard === @js($card['key']) ? 'border-violet-300 bg-white ring-1 ring-violet-200 shadow-sm dark:border-violet-600/70 dark:bg-gray-800 dark:ring-violet-700/50' : 'border-gray-200 bg-white hover:border-violet-200 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-violet-700'"
                class="group inline-flex min-h-11 shrink-0 items-center gap-2.5 rounded-2xl border px-3 py-1.5 transition"
            >
                <x-loops.card-icon :icon="$card['icon'] ?? null" />
                {{-- `labelFor` et non `__($card['label_key'])` : c'est le bouton
                     qu'on lit **en premier**. Sans lui il disait « Roadmap »
                     tandis que le panneau qu'il ouvre disait « Engagements ». --}}
                <span class="text-sm font-bold tracking-tight text-gray-800 dark:text-gray-100">{{ app(\App\Support\Loops\LoopCardRegistry::class)->labelFor($currentLoop, $card['key']) }}</span>
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

        @php($debordement = $toolbarOverflow ?? $secondaryCards ?? collect())
        @if($debordement->isNotEmpty())
        {{-- Le débordement : ce qui ne tient pas dans la barre. Un dépliant,
             pas une nouvelle navigation. Ces outils sont actifs — ils ne sont
             simplement pas les premiers. Quand tout tient, il disparaît. --}}
        <div class="relative shrink-0" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                    class="inline-flex min-h-11 items-center gap-1.5 rounded-2xl border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-600 transition hover:border-violet-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-violet-700">
                {{ __('loops.tools_others_title') }}
                <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ $debordement->count() }}</span>
                <svg class="h-3.5 w-3.5 transition-transform" x-bind:class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </button>

            <div x-show="open" x-cloak x-on:click.outside="open = false"
                 class="absolute left-0 top-full z-30 mt-1.5 w-60 rounded-2xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                @foreach($debordement as $card)
                    <button type="button" x-on:click="openCard(@js($card['key'])); open = false"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left transition hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <x-loops.card-icon :icon="$card['icon'] ?? null" />
                        <span class="min-w-0 truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{{ app(\App\Support\Loops\LoopCardRegistry::class)->labelFor($currentLoop, $card['key']) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
