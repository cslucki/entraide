{{--
    TASK-1621 — « Pour / Contre » : UNE action, et elle ouvre une MODALE.

    Le clic ne genere rien et n'arme meme rien : il explique d'abord. Le
    module envoie la question a deux IA qui ne lisent PAS les Dossiers de la
    Boucle — une promesse qu'il vaut mieux poser avant, plutot que laisser un
    membre s'etonner apres.

    Deux formes, deux points d'insertion, un seul jeu de libelles :
      `pills`  la rangee bureau (`hidden md:flex`) ;
      `tiles`  la feuille mobile, dans sa grille `grid-cols-3` existante.

    COULEURS — pas de `text-white` en dur.
    Les captures humaines de TASK-1620 ont montre du blanc sur fond clair en
    theme LIGHT. L'etat actif utilise donc un fond teinte et un texte teinte de
    la MEME famille, lisible dans les deux themes, au lieu d'un contraste
    suppose.
--}}
@php($variant = $variant ?? 'pills')

@if($assistants !== [])
    @if($variant === 'tiles')
        <button type="button"
                x-on:click="$dispatch('bp-open-pour-contre')"
                data-multi-ai-open
                aria-haspopup="dialog"
                class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition {{ $modeActive ? 'bg-teal-100 dark:bg-teal-900/40' : 'hover:bg-gray-50 dark:hover:bg-gray-700' }}">
            <span class="flex h-10 w-10 items-center justify-center rounded-full transition {{ $modeActive ? 'bg-teal-600 text-teal-50' : 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200' }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
            </span>
            <span class="text-[11px] font-semibold leading-tight {{ $modeActive ? 'text-teal-900 dark:text-teal-100' : 'text-gray-700 dark:text-gray-200' }}">{{ __('loops.plugins_multi_ai_ask_all') }}</span>
        </button>
    @else
        <div class="flex flex-wrap items-center gap-2" data-multi-ai-actions>
            <button type="button"
                    x-on:click="$dispatch('bp-open-pour-contre')"
                    data-multi-ai-open
                    aria-haspopup="dialog"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $modeActive
                        ? 'border-teal-500 bg-teal-100 text-teal-900 hover:bg-teal-200 dark:border-teal-500 dark:bg-teal-900/50 dark:text-teal-100 dark:hover:bg-teal-900/70'
                        : 'border-teal-200 bg-teal-50 text-teal-800 hover:border-teal-300 hover:bg-teal-100 dark:border-teal-800/60 dark:bg-teal-900/25 dark:text-teal-200 dark:hover:bg-teal-900/40' }}">
                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
                {{ __('loops.plugins_multi_ai_ask_all') }}
            </button>
        </div>
    @endif
@endif
