{{--
    TASK-1621 — « Pour / Contre » : UN bouton, qui est l'interrupteur du mode.

    Il n'y a plus de modale. Un ecran qui s'ouvre pour faire confirmer un
    reglage coute un geste de plus a chaque envoi, et n'apprend rien qu'une
    infobulle ne dise aussi bien. Le clic ARME, le re-clic DESARME, et rien
    d'autre n'arrive : aucune generation, aucune publication, aucun blocage.
    Le SUBMIT reste le seul declencheur.

    L'etat se lit SUR le bouton — coche quand il est arme, balance sinon. Il
    n'y a donc plus de badge d'activation separe : deux surfaces pour un meme
    etat, c'est deux occasions qu'elles se contredisent.

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
                wire:click="toggleMultiAiMode"
                data-multi-ai-toggle
                aria-pressed="{{ $modeActive ? 'true' : 'false' }}"
                title="{{ __('loops.plugins_multi_ai_hint') }}"
                class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition {{ $modeActive ? 'bg-teal-100 dark:bg-teal-900/40' : 'hover:bg-gray-50 dark:hover:bg-gray-700' }}">
            <span class="flex h-10 w-10 items-center justify-center rounded-full transition {{ $modeActive ? 'bg-teal-600 text-teal-50' : 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200' }}">
                @if($modeActive)
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                @else
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
                @endif
            </span>
            <span class="text-[11px] font-semibold leading-tight {{ $modeActive ? 'text-teal-900 dark:text-teal-100' : 'text-gray-700 dark:text-gray-200' }}">{{ __('loops.plugins_multi_ai_ask_all') }}</span>
        </button>
    @else
        <div class="flex flex-wrap items-center gap-2" data-multi-ai-actions>
            <button type="button"
                    wire:click="toggleMultiAiMode"
                    data-multi-ai-toggle
                    aria-pressed="{{ $modeActive ? 'true' : 'false' }}"
                    title="{{ __('loops.plugins_multi_ai_hint') }}"
                    class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $modeActive
                        ? 'border-teal-500 bg-teal-100 text-teal-900 hover:bg-teal-200 dark:border-teal-500 dark:bg-teal-900/50 dark:text-teal-100 dark:hover:bg-teal-900/70'
                        : 'border-teal-200 bg-teal-50 text-teal-800 hover:border-teal-300 hover:bg-teal-100 dark:border-teal-800/60 dark:bg-teal-900/25 dark:text-teal-200 dark:hover:bg-teal-900/40' }}">
                @if($modeActive)
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                @else
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
                @endif
                {{ __('loops.plugins_multi_ai_ask_all') }}
            </button>
        </div>
    @endif
@endif
