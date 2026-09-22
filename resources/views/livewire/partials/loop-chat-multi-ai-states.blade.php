{{--
    TASK-1621 — l'etat de « Pour / Contre » pour le tour en cours.

    Deux choses vivent ici, et une seule a la fois se voit :

      1. l'attente, pendant qu'un role prepare ses arguments ;
      2. les echecs, ephemeres : ils n'entrent jamais dans le fil.

    Le badge d'activation a DISPARU (TASK-1621) : l'etat arme se lit sur le
    bouton « Pour / Contre » lui-meme. Deux surfaces pour un meme etat, c'est
    deux occasions qu'elles se contredisent.

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

    @if($queue !== [])
        @php
            // TASK-1621 — la mire porte la couleur du role QU'ELLE ANNONCE :
            // vert pour « Pour », rouge pour « Contre », exactement comme la
            // bulle qui va arriver. Un fond blanc ne disait pas de quel cote
            // du debat on attend quelque chose.
            //
            // C'est la cle TECHNIQUE qui decide, jamais le libelle : celui-ci
            // est traduit, et une couleur ne doit pas dependre d'une locale.
            $couleursMire = $queue[0] === 'traverse'
                ? 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-800/60 dark:bg-rose-900/25 dark:text-rose-100'
                : 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800/60 dark:bg-emerald-900/25 dark:text-emerald-100';
        @endphp

        {{-- `md:hidden` (TASK-1621, addendum UX) : des `md:`, la carte de
             debat porte sa propre mire DANS la colonne du role — ce bandeau
             la repetait mot pour mot juste au-dessus du composeur (constate
             sur la capture de recette du 22/09). Une seule mire visible par
             viewport : ici sur telephone, dans la carte sur ordinateur. --}}
        <div data-multi-ai-pending
             data-multi-ai-pending-role="{{ $queue[0] }}"
             class="flex items-center gap-2 rounded-xl border px-3 py-2 text-xs md:hidden {{ $couleursMire }}">
            {{-- L'icone du SELECTEUR, a gauche : c'est elle qui dit de quel
                 module vient cette attente. Le sablier seul ne le disait pas
                 (retour de Cyril). L'activite reste signalee, mais a droite :
                 l'identite d'abord, l'etat ensuite. --}}
            <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
            {{-- Le role en MINUSCULES : il est cite dans une phrase, pas
                 employe comme un nom propre. --}}
            <span>{{ __('loops.plugins_multi_ai_preparing', ['assistant' => mb_strtolower($labels[$queue[0]] ?? $queue[0])]) }}</span>
            @if(($models[$queue[0]] ?? null))
                {{-- TASK-1621 — QUI prepare. Sans ce nom, deux assistants
                     differents se lisent comme un seul qui repond deux fois.
                     Discret : casse normale, opacite reduite, pas de fond —
                     et le MEME abregement que dans la bulle, pour que le
                     membre reconnaisse le meme nom d'un bout a l'autre. --}}
                <span data-multi-ai-pending-model="{{ $queue[0] }}"
                      class="min-w-0 truncate text-[11px] font-normal opacity-60">{{ $models[$queue[0]] }}</span>
            @endif
            <svg class="ml-auto h-4 w-4 flex-shrink-0 animate-spin opacity-70" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
        </div>
    @endif

    @foreach($states as $key => $state)
        @if($state['status'] === 'not_applicable')
            {{-- TASK-1621 — la question n'a pas de camps a distribuer.
                 NEUTRE, pas ambre : ce n'est ni une panne ni un refus, et
                 « n'a pas pu repondre » serait faux. Aucun nom d'assistant
                 non plus — ce n'est pas un role qui a echoue. Aucun bouton
                 « Reessayer » : la meme question rendrait le meme verdict,
                 c'est la reformulation qui debloque. --}}
            <div data-multi-ai-not-applicable
                 class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-200">
                <p class="leading-5">{{ __('loops.plugins_multi_ai_not_applicable') }}</p>

                <div class="mt-2 flex">
                    <button type="button"
                            wire:click="dismissAssistantState('{{ $key }}')"
                            data-multi-ai-dismiss="{{ $key }}"
                            class="ml-auto text-gray-600 underline-offset-2 hover:underline dark:text-gray-300">
                        {{ __('loops.plugins_multi_ai_dismiss') }}
                    </button>
                </div>
            </div>

            @continue
        @endif

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

        {{-- `md:hidden` : meme regle que la mire — l'echec d'un role se lit
             dans SA colonne de la carte sur ordinateur (data-pour-contre-echec,
             avec le meme « Reessayer »), et ici sur telephone. La notice
             NOT_APPLICABLE au-dessus reste, elle, visible sur TOUS les
             formats : la carte ne la montre jamais. --}}
        <div data-multi-ai-state="{{ $key }}"
             data-multi-ai-status="{{ $state['status'] }}"
             class="rounded-xl border border-amber-300 bg-amber-50 px-3 py-2.5 text-xs md:hidden text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/25 dark:text-amber-100">

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
