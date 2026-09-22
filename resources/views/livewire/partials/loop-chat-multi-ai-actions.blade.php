{{--
    TASK-1620 — UN SEUL interrupteur : « Demander aux 3 IA ».

    TASK-1619 posait ici trois actions (« Demander a Aperio / Traverse /
    Limen ») plus une quatrieme. Deux defauts, et le second est grave :

      1. AMBIGU — quatre actions d'envoi la ou le composeur n'en a qu'une ;
      2. FAUX DECLENCHEUR — le clic lisait le composeur et GENERAIT aussitot,
         sans que le message humain ait ete soumis. Un membre pouvait voir
         « reflechit… » sur un texte qu'il n'avait pas envoye, et payer trois
         generations pour un brouillon.

    Cet interrupteur ne declenche RIEN. Il arme le mode du PROCHAIN envoi,
    exactement comme les interrupteurs IA et Dossiers. Le declencheur est le
    submit du composeur, et lui seul — Entree comprise, puisqu'elle emprunte le
    meme `sendMessage`.

    Deux formes, deux points d'insertion, un seul jeu de libelles :
      `pills`  la rangee bureau (`hidden md:flex`) ;
      `tiles`  la feuille mobile, dans sa grille `grid-cols-3` existante.
--}}
@php($variant = $variant ?? 'pills')

@if($assistants !== [])
    @if($variant === 'tiles')
        <button type="button"
                wire:click="toggleMultiAiMode"
                data-multi-ai-mode
                aria-pressed="{{ $modeActive ? 'true' : 'false' }}"
                class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition {{ $modeActive ? 'bg-teal-50 dark:bg-teal-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-700' }}">
            <span class="flex h-10 w-10 items-center justify-center rounded-full transition {{ $modeActive ? 'bg-teal-600 text-white shadow-sm shadow-teal-500/30' : 'bg-teal-100 text-teal-600 dark:bg-teal-900/40 dark:text-teal-300' }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.1 9.1 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.9 11.9 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6 6 0 0 1 6 18.719m12 .001c0-.568-.079-1.117-.226-1.637m-5.437 3.348A3 3 0 0 0 6 18.72m9-9.22a3 3 0 1 1-6 0 3 3 0 0 1 6 0m6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0m-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0"/></svg>
            </span>
            <span class="text-[11px] font-medium leading-tight {{ $modeActive ? 'text-teal-800 dark:text-teal-100' : 'text-gray-700 dark:text-gray-200' }}">{{ __('loops.plugins_multi_ai_ask_all') }}</span>
        </button>
    @else
        <div class="flex flex-wrap items-center gap-2" data-multi-ai-actions>
            <button type="button"
                    wire:click="toggleMultiAiMode"
                    data-multi-ai-mode
                    aria-pressed="{{ $modeActive ? 'true' : 'false' }}"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $modeActive
                        ? 'border-teal-400 bg-teal-600 text-white hover:bg-teal-700 dark:border-teal-500'
                        : 'border-teal-200 bg-teal-50/70 text-teal-700 hover:border-teal-300 hover:bg-teal-100 dark:border-teal-800/50 dark:bg-teal-900/20 dark:text-teal-200 dark:hover:bg-teal-900/40' }}">
                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.1 9.1 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.9 11.9 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6 6 0 0 1 6 18.719m12 .001c0-.568-.079-1.117-.226-1.637m-5.437 3.348A3 3 0 0 0 6 18.72m9-9.22a3 3 0 1 1-6 0 3 3 0 0 1 6 0m6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0m-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0"/></svg>
                {{ __('loops.plugins_multi_ai_ask_all') }}
                @if($modeActive)<span aria-hidden="true">×</span>@endif
            </button>
        </div>
    @endif
@endif
