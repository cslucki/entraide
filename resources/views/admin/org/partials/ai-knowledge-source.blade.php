{{--
    TASK-1307 — contenu du drawer « Inspecter » : ce que BouclePro sait
    REELLEMENT d'une source. Metadonnees + texte des chunks stockes, jamais
    le vecteur embedding.
--}}
<div class="space-y-4">
    <div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $source['title'] }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_dossier') }} : {{ $source['dossier_name'] }}</p>
    </div>

    @if(! $source['indexed'])
        <p class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
            {{ __('ai.knowledge_console_state_not_indexed') }}
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('ai.observatory_chunks_count', count($source['chunks']), ['count' => count($source['chunks'])]) }}</p>
        <ol class="space-y-3">
            @foreach($source['chunks'] as $chunk)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
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
