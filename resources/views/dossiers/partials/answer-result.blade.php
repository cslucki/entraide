{{-- TASK-1516 — « Interroger ce Dossier ». Fragment HTML rendu SERVEUR et
     insere par Alpine, exactement comme `insights-result` : aucun rendu metier
     cote JS, aucun markdown interprete dans le navigateur.

     `$answer` est un App\Services\Ai\DTO\KnowledgeAnswer — le meme contrat que
     Smart Dossier et que la reponse documentaire de Boucle.

     Les boutons portent des crochets `data-*` et AUCUNE directive Alpine :
     Alpine n'initialise pas le contenu injecte par `x-html`. C'est la racine
     de la section qui delegue le clic (meme motif que TASK-1515). --}}
@php
    $usedSources = array_map(\App\Services\Ai\DTO\KnowledgeAnswer::publicSource(...), $answer->sources);
    $consultedSources = array_map(\App\Services\Ai\DTO\KnowledgeAnswer::publicSource(...), $answer->consulted);
@endphp
<div data-dossier-answer-result class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
    {{-- La REPONSE d'abord. C'est elle le produit ; les passages viennent
         apres, et repliés. --}}
    <div class="prose prose-sm max-w-none text-gray-800 dark:prose-invert dark:text-gray-100" data-dossier-answer-body>
        {!! markdown($answer->answer) !!}
    </div>

    @unless($answer->grounded)
        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200" data-dossier-answer-ungrounded>
            {{ __('dossiers.answer_not_grounded') }}
        </p>
    @endunless

    @if($usedSources !== [])
        <div class="mt-4" data-dossier-answer-sources>
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">{{ __('dossiers.answer_sources_heading') }}</p>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach($usedSources as $source)
                    <li>
                        @if($source['url'])
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-white px-3 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:bg-gray-900 dark:text-indigo-300"
                               data-dossier-answer-source="{{ $source['ref'] }}">
                                <span>{{ $source['ref'] }}</span>
                                <span class="max-w-[16rem] truncate">{{ $source['title'] ?? $source['dossier_name'] }}</span>
                            </a>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                  data-dossier-answer-source="{{ $source['ref'] }}">
                                <span>{{ $source['ref'] }}</span>
                                <span class="max-w-[16rem] truncate">{{ $source['title'] ?? $source['dossier_name'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($consultedSources !== [])
        {{-- Transparence : les extraits BRUTS qui ont servi. Utiles, mais plus
             le produit principal — ils sont replies par defaut (CDC §9). --}}
        <div class="mt-4">
            <button type="button"
                    class="text-xs font-semibold text-indigo-700 underline underline-offset-2 hover:no-underline dark:text-indigo-300"
                    data-dossier-answer-passages-toggle
                    data-label-show="{{ __('dossiers.answer_passages_show') }}"
                    data-label-hide="{{ __('dossiers.answer_passages_hide') }}">{{ __('dossiers.answer_passages_show') }}</button>

            <ol class="mt-3 space-y-3" data-dossier-answer-passages hidden>
                @foreach($consultedSources as $source)
                    <li class="rounded-xl border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-900/40" data-dossier-answer-passage="{{ $source['ref'] }}">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $source['ref'] }} · {{ $source['title'] ?? $source['dossier_name'] }}
                        </p>
                        <p class="mt-1.5 leading-6 text-gray-700 dark:text-gray-300">{{ $source['excerpt'] }}</p>
                        @if($source['url'])
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="mt-2 inline-block text-xs font-semibold text-indigo-700 underline underline-offset-2 hover:no-underline dark:text-indigo-300">{{ __('dossiers.answer_open_document') }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if($answer->followUps !== [])
        {{-- Approfondissements. Un clic ne repond pas ici : il ouvre le Shell
             et y prepare la question, pour que la conversation se poursuive
             dans l'UNIQUE fil existant (CDC §6). --}}
        <div class="mt-5 border-t border-indigo-100 pt-4 dark:border-indigo-900/50" data-dossier-answer-follow-ups>
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">{{ __('dossiers.answer_follow_ups_heading') }}</p>
            <ul class="mt-2 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                @foreach($answer->followUps as $question)
                    <li>
                        <button type="button"
                                class="inline-flex min-h-[40px] items-center rounded-full border border-indigo-200 bg-white px-4 py-2 text-left text-xs font-medium text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-800 dark:bg-gray-900 dark:text-indigo-300 dark:hover:bg-gray-800"
                                data-dossier-answer-follow-up="{{ $question }}">{{ $question }}</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
