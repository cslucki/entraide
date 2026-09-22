{{--
    TASK-1621 — l'etat de « Pour / Contre » pour le tour en cours.

    Trois choses vivent ici, et une seule a la fois se voit :

      1. le BADGE d'activation, unique. TASK-1620 en avait deux concurrents
         (le bouton actif ET une pastille) et le mandat en demande UN ;
      2. l'attente, pendant qu'un role prepare ses arguments ;
      3. les echecs, ephemeres : ils n'entrent jamais dans le fil.

    La REQUETE DIFFEREE est ici aussi. `wire:init` declenche la generation
    APRES que le message humain a ete publie et rendu. La cle change a chaque
    role consomme, ce qui reinsere l'element et relance un tour — precedent du
    depot : `loop-ai-summary-card.blade.php`.

    COULEURS : aucun `text-white`/`text-black` en dur. Les captures humaines de
    TASK-1620 montraient du blanc sur fond clair en theme LIGHT.
--}}
<div data-multi-ai-states role="status" aria-live="polite" aria-atomic="false" class="flex flex-col gap-2">

    @if($queue !== [])
        {{-- Le declencheur differe. Invisible, sans effet visuel, sans boucle :
             la cle du role est consommee cote serveur AVANT le moindre appel. --}}
        <div wire:init="runNextPourContre" wire:key="pour-contre-{{ count($queue) }}" class="hidden"></div>
    @endif

    @if($modeActive)
        <div data-multi-ai-armed
             class="inline-flex w-fit items-center gap-2 rounded-full border border-teal-300 bg-teal-100 px-3 py-1.5 text-xs font-semibold text-teal-900 dark:border-teal-700 dark:bg-teal-900/50 dark:text-teal-100">
            <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
            {{ __('loops.plugins_multi_ai_armed') }}
            <button type="button" wire:click="toggleMultiAiMode" data-multi-ai-disarm
                    aria-label="{{ __('loops.plugins_multi_ai_disable') }}"
                    class="ml-0.5 rounded-full px-1 text-teal-800 transition hover:bg-teal-200 dark:text-teal-200 dark:hover:bg-teal-800">×</button>
        </div>
    @endif

    @if($queue !== [])
        <div data-multi-ai-pending
             class="flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-900 dark:border-teal-800/60 dark:bg-teal-900/25 dark:text-teal-100">
            {{-- L'icone du SELECTEUR, a gauche : c'est elle qui dit de quel
                 module vient cette attente. Le sablier seul ne le disait pas
                 (retour de Cyril). L'activite reste signalee, mais a droite :
                 l'identite d'abord, l'etat ensuite. --}}
            <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
            {{-- Le role en MINUSCULES : il est cite dans une phrase, pas
                 employe comme un nom propre. --}}
            <span>{{ __('loops.plugins_multi_ai_preparing', ['assistant' => mb_strtolower($labels[$queue[0]] ?? $queue[0])]) }}</span>
            <svg class="ml-auto h-4 w-4 flex-shrink-0 animate-spin opacity-70" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        </div>
    @endif

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
             class="rounded-xl border border-amber-300 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/25 dark:text-amber-100">

            <p class="font-semibold">{{ $titre }}</p>
            <p class="mt-0.5 text-amber-800 dark:text-amber-200">{{ $corps }}</p>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                @if($state['retryable'])
                    <button type="button"
                            wire:click="retryAssistant('{{ $key }}')"
                            wire:loading.attr="disabled"
                            wire:target="sendMessage,retryAssistant,runNextPourContre"
                            data-multi-ai-retry="{{ $key }}"
                            class="inline-flex items-center gap-1.5 rounded-full border border-amber-400 bg-white px-2.5 py-1 font-semibold text-amber-900 transition hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-600 dark:bg-amber-950/50 dark:text-amber-100">
                        {{ __('loops.plugins_multi_ai_retry') }}
                    </button>
                @endif

                <button type="button"
                        wire:click="dismissAssistantState('{{ $key }}')"
                        data-multi-ai-dismiss="{{ $key }}"
                        class="ml-auto text-amber-800 underline-offset-2 hover:underline dark:text-amber-300">
                    {{ __('loops.plugins_multi_ai_dismiss') }}
                </button>
            </div>
        </div>
    @endforeach
</div>
