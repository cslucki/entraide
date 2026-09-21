{{--
    TASK-1619 / SLICE E — les boutons des 3 assistants IA.

    UNE partielle, DEUX formes, DEUX points d'insertion :

      `pills`  la rangee bureau (`hidden md:flex`), pastilles en ligne ;
      `tiles`  la feuille mobile du `+`, tuiles dans sa grille `grid-cols-3`
               existante — la partielle n'emet alors AUCUN conteneur, elle
               s'insere dans la grille de l'appelant.

    Deux formes plutot qu'une seule forcee dans les deux : la feuille mobile
    est une grille de tuiles depuis TASK-1329, parce que cinq lignes pleine
    largeur mangeaient un tiers de l'ecran — constat de recette, pas de gout.
    Y plaquer des pastilles aurait refait ce probleme.

    Ce qui reste COMMUN est ce qu'un test asserte : les libelles, et les
    attributs `data-multi-ai-*`. Ils ne peuvent pas diverger, ils sont ecrits
    une fois.
--}}
@php($variant = $variant ?? 'pills')

@if($assistants !== [])
    @if($variant === 'tiles')
        @foreach($assistants as $assistant)
            <button type="button"
                    wire:click="askAssistant('{{ $assistant['key'] }}')"
                    wire:loading.attr="disabled"
                    wire:target="askAssistant,askAllAssistants,retryAssistant,synthesiseAssistants"
                    data-multi-ai-ask="{{ $assistant['key'] }}"
                    class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 disabled:opacity-50 dark:hover:bg-gray-700">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-teal-100 text-teal-600 dark:bg-teal-900/40 dark:text-teal-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0m3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0m3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0M21 12c0 4.556-4.03 8.25-9 8.25a9.8 9.8 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a6 6 0 0 1-.474-.065 4.5 4.5 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25"/></svg>
                </span>
                <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ $assistant['label'] }}</span>
            </button>
        @endforeach

        @if(count($assistants) > 1)
            <button type="button"
                    wire:click="askAllAssistants"
                    wire:loading.attr="disabled"
                    wire:target="askAssistant,askAllAssistants,retryAssistant,synthesiseAssistants"
                    data-multi-ai-ask-all
                    class="flex flex-col items-center gap-1.5 rounded-xl bg-teal-50 px-1.5 py-2 text-center transition hover:bg-teal-100 disabled:opacity-50 dark:bg-teal-900/30 dark:hover:bg-teal-900/50">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-teal-600 text-white shadow-sm shadow-teal-500/30">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.1 9.1 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.9 11.9 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6 6 0 0 1 6 18.719m12 .001c0-.568-.079-1.117-.226-1.637m-5.437 3.348A3 3 0 0 0 6 18.72m9-9.22a3 3 0 1 1-6 0 3 3 0 0 1 6 0m6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0m-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0"/></svg>
                </span>
                <span class="text-[11px] font-semibold leading-tight text-teal-800 dark:text-teal-100">{{ __('loops.plugins_multi_ai_ask_all') }}</span>
            </button>
        @endif
    @else
        <div class="flex flex-wrap items-center gap-2" data-multi-ai-actions>
            @foreach($assistants as $assistant)
                <button type="button"
                        wire:click="askAssistant('{{ $assistant['key'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="askAssistant,askAllAssistants,retryAssistant,synthesiseAssistants"
                        data-multi-ai-ask="{{ $assistant['key'] }}"
                        class="inline-flex items-center gap-2 rounded-full border border-teal-200 bg-teal-50/70 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:border-teal-300 hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-teal-800/50 dark:bg-teal-900/20 dark:text-teal-200 dark:hover:bg-teal-900/40">
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0m3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0m3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0M21 12c0 4.556-4.03 8.25-9 8.25a9.8 9.8 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a6 6 0 0 1-.474-.065 4.5 4.5 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25"/></svg>
                    {{ __('loops.plugins_multi_ai_ask_one', ['assistant' => $assistant['label']]) }}
                </button>
            @endforeach

            @if(count($assistants) > 1)
                <button type="button"
                        wire:click="askAllAssistants"
                        wire:loading.attr="disabled"
                        wire:target="askAssistant,askAllAssistants,retryAssistant,synthesiseAssistants"
                        data-multi-ai-ask-all
                        class="inline-flex items-center gap-2 rounded-full border border-teal-400 bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-teal-500">
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.1 9.1 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.9 11.9 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6 6 0 0 1 6 18.719m12 .001c0-.568-.079-1.117-.226-1.637m-5.437 3.348A3 3 0 0 0 6 18.72m9-9.22a3 3 0 1 1-6 0 3 3 0 0 1 6 0m6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0m-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0"/></svg>
                    {{ __('loops.plugins_multi_ai_ask_all') }}
                </button>
            @endif

            {{-- DECOUVRABILITE — dette UX_DEBT_SLICE_E de TASK-1616. Le droit
                 `loop_plugins.configure` et sa route existaient depuis SLICE B ;
                 rien n'y menait pour un facilitateur, que `/outils` refuse au
                 titre de la doctrine des Cards — qu'on ne touche pas. Le lien
                 manquant vit ici, dans la surface ou le plugin SERT. --}}
            @if($configureUrl !== null)
                <a href="{{ $configureUrl }}"
                   data-multi-ai-configure
                   class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-xs font-medium text-slate-500 transition hover:text-teal-700 hover:underline dark:text-slate-400 dark:hover:text-teal-300">
                    <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0 0 15 0m-15 0a7.5 7.5 0 1 1 15 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077 1.41-.513m14.095-5.13 1.41-.513M5.106 17.785l1.15-.964m11.49-9.642 1.149-.964M7.501 19.795l.75-1.3m7.5-12.99.75-1.3m-6.063 16.658.26-1.477m2.605-14.772.26-1.477m0 17.726-.26-1.477M10.698 4.614l-.26-1.477M16.5 19.794l-.75-1.299M7.5 4.205 12 12m6.894 5.785-1.149-.964M6.256 7.178l-1.15-.964"/></svg>
                    {{ __('loops.plugins_multi_ai_discover') }}
                </a>
            @endif
        </div>
    @endif
@endif
