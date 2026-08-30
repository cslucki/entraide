{{-- TASK-1341 — Smart Dossier V1. Fragment HTML rendu serveur, insere par
     Alpine (aucun rendu metier cote JS). `$answer` est une
     App\Services\Ai\DTO\KnowledgeAnswer — meme contrat que la reponse
     documentaire de Boucle. --}}
@php
    $usedSources = array_map(\App\Services\Ai\DTO\KnowledgeAnswer::publicSource(...), $answer->sources);
    $consultedSources = $answer->sources === []
        ? array_map(\App\Services\Ai\DTO\KnowledgeAnswer::publicSource(...), $answer->consulted)
        : [];
@endphp
<div data-dossier-insights-result class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.insights_help') }}</p>

    <div class="prose prose-sm mt-3 max-w-none text-gray-800 dark:prose-invert dark:text-gray-100">
        {!! markdown($answer->answer) !!}
    </div>

    @if($usedSources !== [])
        <div class="mt-4" data-sources-kind="used">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">{{ __('dossiers.insights_sources_used') }}</p>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach($usedSources as $source)
                    <li>
                        @if($source['url'])
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-white px-3 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:bg-gray-900 dark:text-indigo-300">
                                <span>{{ $source['ref'] }}</span>
                                <span class="max-w-[16rem] truncate">{{ $source['title'] ?? $source['dossier_name'] }}</span>
                            </a>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <span>{{ $source['ref'] }}</span>
                                <span class="max-w-[16rem] truncate">{{ $source['title'] ?? $source['dossier_name'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif($consultedSources !== [])
        <div class="mt-4" data-sources-kind="consulted">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('dossiers.insights_sources_consulted') }}</p>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach($consultedSources as $source)
                    <li>
                        @if($source['url'])
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <span>{{ $source['ref'] }}</span>
                                <span class="max-w-[16rem] truncate">{{ $source['title'] ?? $source['dossier_name'] }}</span>
                            </a>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <span>{{ $source['ref'] }}</span>
                                <span class="max-w-[16rem] truncate">{{ $source['title'] ?? $source['dossier_name'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
