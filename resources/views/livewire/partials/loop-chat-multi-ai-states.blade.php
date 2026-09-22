{{--
    TASK-1619 / SLICE E — l'etat des assistants du tour en cours.

    EPHEMERE et PERSONNEL (arbitrage MASTER, 21/09) : rien de ce bloc n'entre
    dans le fil. Les reponses REUSSIES sont deja des bulles permanentes plus
    haut ; ce qui reste ici est ce que la conversation ne doit pas garder.

    Aucun code technique n'est affiche. `PROVIDER_CALL_FAILED`, `HTTP 429` et
    `upstream_provider_shared_pool` restent dans les traces SuperAdmin — le
    membre lit une phrase, pas un diagnostic.

    `aria-live="polite"` : l'indisponibilite arrive APRES le clic, sans
    rechargement. Sans annonce, un lecteur d'ecran ne saurait jamais que la
    demande a echoue — l'utilisateur attendrait une reponse qui ne vient pas.
--}}
<div data-multi-ai-states role="status" aria-live="polite" aria-atomic="false" class="flex flex-col gap-2">

    {{-- « en cours » : trois generations sequentielles sont longues, et un
         bouton qui ne dit rien pendant dix secondes se reclique. --}}
    <div wire:loading wire:target="sendMessage,retryAssistant,synthesiseAssistants"
         data-multi-ai-pending
         class="flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50/70 px-3 py-2 text-xs text-teal-800 dark:border-teal-800/50 dark:bg-teal-900/20 dark:text-teal-200">
        <svg class="h-4 w-4 flex-shrink-0 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        <span>{{ __('loops.plugins_multi_ai_working') }}</span>
    </div>

    @foreach($states as $key => $state)
        @php
            [$titre, $corps] = match ($state['status']) {
                'rate_limited' => [
                    __('loops.plugins_multi_ai_rate_limited_title', ['assistant' => $state['label']]),
                    __('loops.plugins_multi_ai_rate_limited_body', ['assistant' => $state['label']]),
                ],
                'refused' => [
                    __('loops.plugins_multi_ai_refused_title', ['assistant' => $state['label']]),
                    __('loops.plugins_multi_ai_refused_body', ['assistant' => $state['label']]),
                ],
                default => [
                    __('loops.plugins_multi_ai_failed_title', ['assistant' => $state['label']]),
                    __('loops.plugins_multi_ai_failed_body'),
                ],
            };
        @endphp

        <div data-multi-ai-state="{{ $key }}"
             data-multi-ai-status="{{ $state['status'] }}"
             class="rounded-xl border border-amber-200 bg-amber-50/80 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-100">

            <p class="font-semibold">{{ $titre }}</p>
            <p class="mt-0.5 text-amber-800 dark:text-amber-200/90">{{ $corps }}</p>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                {{-- « Reessayer » n'existe QUE pour la saturation : c'est le
                     seul echec dont ce soit le remede. Le proposer sur un
                     modele non configure donnerait un bouton qui ne peut pas
                     aboutir. --}}
                @if($state['retryable'])
                    <button type="button"
                            wire:click="retryAssistant('{{ $key }}')"
                            wire:loading.attr="disabled"
                            wire:target="sendMessage,retryAssistant,synthesiseAssistants"
                            data-multi-ai-retry="{{ $key }}"
                            class="inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-white/70 px-2.5 py-1 font-semibold text-amber-800 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100">
                        {{ __('loops.plugins_multi_ai_retry') }}
                    </button>
                @endif

                <button type="button"
                        wire:click="dismissAssistantState('{{ $key }}')"
                        data-multi-ai-dismiss="{{ $key }}"
                        class="ml-auto text-amber-700 underline-offset-2 hover:underline dark:text-amber-300">
                    {{ __('loops.plugins_multi_ai_dismiss') }}
                </button>
            </div>
        </div>
    @endforeach

    {{-- La SYNTHESE, et elle est un GESTE. Aucune IA ne relit une autre IA sans
         qu'une personne l'ait demande : « Demander aux 3 » fait repondre les
         trois en pairs, jamais l'un sur l'autre. --}}
    @if($canSynthesise)
        <button type="button"
                wire:click="synthesiseAssistants"
                wire:loading.attr="disabled"
                wire:target="sendMessage,retryAssistant,synthesiseAssistants"
                data-multi-ai-synthesise
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50/70 px-3 py-2 text-xs font-semibold text-indigo-800 transition hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-indigo-800/50 dark:bg-indigo-900/20 dark:text-indigo-200 sm:w-auto">
            <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5a4.5 4.5 0 0 0 0-9H15M16.5 3 21 7.5"/></svg>
            {{ __('loops.plugins_multi_ai_synthesise', ['assistant' => $synthesiserLabel]) }}
        </button>
    @endif
</div>
