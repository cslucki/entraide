{{--
    TASK-1551 — « Sur quoi cette réponse se fonde », dans le Shell.

    Ce panneau est une LECTURE. Il ne porte aucun formulaire, aucune machine à
    états, aucun geste d'écriture — et ce n'est pas une règle de vue :
    `ClaimProvenanceReader::provenance()` reçoit `null` comme Boucle courante
    depuis cet hôte, donc `can_correct` y vaut `false` par construction. Corriger
    une mémoire durable se fait dans la Boucle qui la porte, par le chemin
    standard livré par T1549 ; ce panneau y CONDUIT, il ne le double pas.

    Les libellés de la section mémoire sont ceux de `loops.php`, réutilisés tels
    quels : une deuxième formulation de « constaté le… » et de la portée aurait
    divergé de celle du ChatLoop au premier ajustement.

    Ce qui n'entre jamais ici : aucun `subject_key`, aucun identifiant technique
    affiché, aucun score, aucune chaîne de pensée. Les refus sont des NOMBRES —
    ni auteur, ni titre, ni contenu.

    Variable attendue : $panel — {memory, documents, unreachable_count}
--}}
<div class="mt-2 w-full max-w-[85%] rounded-2xl border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-800"
     data-ai-shell-why-panel>
    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
        {{ __('ai.shell_why_title') }}
    </p>

    @php($memory = $panel['memory'] ?? null)
    @if($memory !== null && ($memory['entries'] !== [] || $memory['denied_count'] > 0))
    <div class="mt-2 rounded-xl border border-violet-200 bg-violet-50/40 p-2.5 dark:border-violet-900/40 dark:bg-violet-950/20"
         data-ai-shell-why-memory>
        <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">
            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
            {{ __('ai.shell_why_memory_title') }}
        </p>
        @if($memory['entries'] !== [])
        <ul class="mt-1.5 space-y-1.5">
            @foreach($memory['entries'] as $entry)
            <li class="rounded-lg border border-violet-200/70 bg-white px-2.5 py-1.5 dark:border-violet-800/50 dark:bg-gray-900"
                data-ai-shell-why-memory-entry data-memory-state="{{ $entry['state'] }}">
                <p class="leading-5 font-semibold text-gray-900 dark:text-gray-100">{{ $entry['statement'] }}</p>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] leading-4 text-gray-500 dark:text-gray-400">
                    @if($entry['observed_at'])<span>{{ __('loops.why_memory_observed', ['date' => $entry['observed_at']]) }}</span>@endif
                    {{-- La portée nomme la Boucle ET dit où la correction s'écrit.
                         `same_loop` est toujours faux depuis le Shell : on n'est
                         dans aucune conversation, donc jamais « ici ». --}}
                    <span>{{ __('loops.why_memory_scope_other', ['loop' => $entry['loop_name']]) }}</span>
                    @if($entry['loop_url'] !== null)
                    <a href="{{ $entry['loop_url'] }}" data-ai-shell-why-loop-link
                       class="font-semibold text-violet-700 underline decoration-dotted underline-offset-2 dark:text-violet-300">
                        {{ __('ai.shell_why_open_loop') }}
                    </a>
                    @endif
                </div>
                @if($entry['corrections'] !== [])
                <div class="mt-1 space-y-0.5" data-ai-shell-why-corrections>
                    @foreach($entry['corrections'] as $correctionEvent)
                    <p class="text-[11px] leading-4 text-gray-500 dark:text-gray-400">
                        {{ $correctionEvent['by_name'] !== null
                            ? __('loops.why_memory_corrected_by', ['name' => $correctionEvent['by_name'], 'date' => $correctionEvent['at'] ?? '—'])
                            : __('loops.why_memory_corrected', ['date' => $correctionEvent['at'] ?? '—']) }}
                    </p>
                    @endforeach
                </div>
                @endif
            </li>
            @endforeach
        </ul>
        @endif
        @if($memory['denied_count'] > 0)
        <p class="mt-1.5 leading-5 text-amber-700 dark:text-amber-300" data-ai-shell-why-memory-denied="{{ $memory['denied_count'] }}">
            {{ trans_choice('loops.why_memory_denied', $memory['denied_count']) }}
        </p>
        @endif
    </div>
    @endif

    @php($documents = $panel['documents'] ?? null)
    @if($documents !== null && ($documents['entries'] !== [] || $documents['masked_count'] > 0))
    <div class="mt-2" data-ai-shell-why-documents>
        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('ai.shell_why_documents_title') }}
        </p>
        @if($documents['entries'] !== [])
        <ul class="mt-1 space-y-0.5">
            @foreach($documents['entries'] as $doc)
            <li class="leading-5 text-gray-700 dark:text-gray-200" data-ai-shell-why-document-entry>
                <span class="font-medium">{{ $doc['title'] ?? '—' }}</span>
                @if($doc['dossier_name'])<span class="text-gray-500 dark:text-gray-400"> · {{ $doc['dossier_name'] }}</span>@endif
            </li>
            @endforeach
        </ul>
        @endif
        @if($documents['masked_count'] > 0)
        <p class="mt-1 leading-5 text-amber-700 dark:text-amber-300" data-ai-shell-why-documents-masked="{{ $documents['masked_count'] }}">
            {{ trans_choice('ai.shell_why_documents_masked', $documents['masked_count']) }}
        </p>
        @endif
    </div>
    @endif

    {{-- Le TROISIÈME état, hérité de T1549 : une source citée dont la ligne a
         disparu n'a plus d'origine établissable. Elle se dit SANS nommer de
         famille — ni document, ni mémoire — parce que rien ne permet plus de
         trancher (dette W5/TRACE-0, rendue telle quelle). --}}
    @if(($panel['unreachable_count'] ?? 0) > 0)
    <p class="mt-2 leading-5 text-gray-500 dark:text-gray-400" data-ai-shell-why-unreachable="{{ $panel['unreachable_count'] }}">
        {{ trans_choice('loops.why_source_unreachable', $panel['unreachable_count']) }}
    </p>
    @endif
</div>
