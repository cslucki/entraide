{{--
    TASK-1307 — contenu du drawer « Inspecter » : ce que BouclePro sait
    REELLEMENT d'une source. Metadonnees + texte des chunks stockes, jamais
    le vecteur embedding.

    TASK-1515 — ce partiel sert desormais DEUX consoles : celle de
    l'Organization et celle de la plateforme (`/admin/drives`). Il ne prend
    toujours qu'une variable, `$source`, et n'ecrit aucun `var(--bp-*)` :
    `layouts/admin` n'emet pas ces jetons, la couleur y serait un no-op.

    Le compte annonce est le TOTAL (`total_chunks`), pas le nombre de lignes
    rendues : la plateforme borne l'affichage, et un « 20 extraits » sur un
    document qui en compte 312 serait un mensonge tranquille.
--}}
@php
    $renderedChunks = $source['chunks'] ?? [];
    $totalChunks = (int) ($source['total_chunks'] ?? count($renderedChunks));
    $shownChunks = count($renderedChunks);
@endphp
<div class="space-y-4" data-source-panel data-source-total="{{ $totalChunks }}" data-source-shown="{{ $shownChunks }}">
    <div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100" data-source-title>{{ $source['title'] }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_dossier') }} : {{ $source['dossier_name'] }}</p>
    </div>

    @if(! $source['indexed'])
        <p class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300" data-source-not-indexed>
            {{ __('ai.knowledge_console_state_not_indexed') }}
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400" data-source-count>{{ trans_choice('ai.observatory_chunks_count', $totalChunks, ['count' => $totalChunks]) }}</p>

        @if($shownChunks < $totalChunks)
            <p class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300" data-source-truncated>
                {{ __('ai.knowledge_console_chunks_truncated', ['shown' => $shownChunks, 'total' => $totalChunks]) }}
            </p>
        @endif

        <ol class="space-y-3" data-source-chunks>
            @foreach($renderedChunks as $chunk)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700" data-source-chunk="{{ $chunk['chunk_index'] }}">
                    <div class="mb-1.5 flex items-center justify-between text-xs text-gray-400 dark:text-gray-500">
                        <span>{{ __('ai.knowledge_console_chunk_label', ['index' => $chunk['chunk_index']]) }} · {{ $chunk['token_count'] }} tok</span>
                        <span>{{ $chunk['indexed_at'] ? \Illuminate\Support\Carbon::parse($chunk['indexed_at'])->isoFormat('D MMM YYYY HH:mm') : '—' }}</span>
                    </div>
                    <p class="whitespace-pre-line text-sm text-gray-800 dark:text-gray-200">{{ $chunk['content'] }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</div>
