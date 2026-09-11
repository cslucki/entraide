{{--
    TASK-1533 — le TOUR observe, rendu inline par le POST.

    Ce fragment est la reponse HTTP de la requete qui a execute la question :
    meme administrateur, meme Organization, meme instant. Rien n'en est flashe
    en session, et l'entete `no-store` l'interdit de cache — parce qu'il porte
    des extraits de documents du tenant (bloc de provenance).

    Il contient QUATRE panneaux que le JS repartit dans la grille de
    l'instrument. Les rendre ensemble garantit qu'ils decrivent tous le meme
    tour : une trace et une telemetrie obtenues par deux requetes pourraient
    decrire deux tours differents.

    Regles de verite :
      - une valeur absente s'affiche « — », jamais 0 ni une estimation ;
      - une source refusee se nomme par sa RAISON traduite : ni contenu, ni
        titre, ni identifiant, et AUCUN bloc de provenance ni declencheur de
        tiroir — pas meme vide, qui se lirait comme « il y a quelque chose » ;
      - une source autorisee restee vide est dite vide, pas « non consultee » ;
      - aucune cle, aucun credential, aucun prompt compose.
--}}
@php
    // Le libelle d'une source, avec repli sur son identifiant technique : cet
    // ecran est un outil de diagnostic, un nom brut y est plus honnete qu'un
    // libelle invente.
    $sourceLabel = static function (string $name): string {
        $key = 'ai.inspector_source_label.'.str_replace('.', '_', $name);

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $name;
    };
    $deniedLabel = static function (string $reason): string {
        $key = 'ai.behavior_sandbox_source_denied.'.$reason;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : __('ai.behavior_sandbox_source_denied.other');
    };
    $value = static fn ($raw): string => ($raw === null || $raw === '') ? '—' : (string) $raw;

    $status = (string) ($result['status'] ?? '');
    $capability = (string) ($result['capability'] ?? '');
    $usedSources = is_array($result['sources_used'] ?? null) ? $result['sources_used'] : [];
    $deniedSources = is_array($result['sources_denied'] ?? null) ? $result['sources_denied'] : [];

    // Un refus intervient TOUJOURS avant `ContextBuilder::build()` : capability
    // non testable, fonction desactivee, Organization sans credential, budget
    // atteint, instruction absente. Aucune source n'a donc ete interrogee.
    //
    // Sans cette distinction, l'ecart « autorisee moins utilisee moins refusee »
    // valait la totalite des sources et les affichait VIDES — soit exactement la
    // confusion que le CDC interdit : « source non demandee n'est pas source
    // vide ». La trace disait « etape non atteinte » pendant que les cartes
    // disaient « consultee, rien trouve ».
    $contextReached = $status !== 'refused';

    // Autorisee, ni utilisee ni refusee = consultee, sans rien a dire. Le
    // `ContextBuilder` ne la comptabilise nulle part (`$fragment->isEmpty()`
    // -> `continue`) : sans ce troisieme etat, elle disparaitrait de l'ecran.
    $emptySources = $contextReached
        ? array_values(array_diff($allowedSources, $usedSources, array_keys($deniedSources)))
        : [];
    $notRequestedSources = $contextReached ? [] : array_values($allowedSources);

    // L'etat de RUN, decide par le serveur. `no_sources` est une reussite : un
    // pipeline qui constate « rien a dire » a fait son travail, et la trace le
    // montre a l'etape CONTEXTE. PARTIAL est reporte : aucune etape ne sait
    // aujourd'hui echouer independamment.
    $runState = match ($status) {
        'answered', 'no_sources' => 'success',
        'refused' => 'refused',
        default => 'error',
    };

    // Projection de la carte de contexte : la carte n'invente aucun etat, elle
    // recoit celui que le tour a mesure.
    $sourceStates = [];
    foreach ($usedSources as $name) {
        $sourceStates[$name] = 'used';
    }
    foreach ($emptySources as $name) {
        $sourceStates[$name] = 'empty';
    }
    foreach (array_keys($deniedSources) as $name) {
        $sourceStates[$name] = 'denied';
    }
    foreach ($notRequestedSources as $name) {
        $sourceStates[$name] = 'not_requested';
    }

    // Provenance regroupee par source. Elle ne decrit que des sources UTILISEES
    // (le builder ne collecte rien d'une source refusee) ; le filtre ci-dessous
    // le rend vrai par construction plutot que par confiance.
    $provenance = [];
    foreach ((is_array($result['provenance'] ?? null) ? $result['provenance'] : []) as $entry) {
        $source = (string) ($entry['source'] ?? '');

        if ($source === '' || ! in_array($source, $usedSources, true)) {
            continue;
        }

        $provenance[$source][] = $entry;
    }

    $ledgerEntries = (int) ($result['ledger_entries'] ?? 0);
    $correlationId = $result['correlation_id'] ?? null;

    $stateChip = static fn (string $tone): string => match ($tone) {
        'used' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
        'denied' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
        'error' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20',
        default => 'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-700/60 dark:text-gray-300 dark:ring-gray-500/30',
    };
@endphp

<div data-inspector-run
     data-inspector-status="{{ $status }}"
     data-inspector-run-state="{{ $runState }}"
     data-inspector-capability="{{ $capability }}"
     data-inspector-generated-at="{{ $generatedAt }}"
     data-inspector-source-states="{{ json_encode($sourceStates, JSON_UNESCAPED_UNICODE) }}">

    {{-- ============================ REPONSE ============================ --}}
    <div data-inspector-pane="answer">
        @if($status === 'answered')
            <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('ai.inspector_answer_title') }}</p>
            <div class="mt-2 whitespace-pre-wrap text-[15px] leading-relaxed text-gray-900 dark:text-gray-100" data-inspector-answer>{{ $result['answer'] ?? '' }}</div>
        @elseif($status === 'no_sources')
            <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('ai.inspector_answer_title') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300" data-inspector-no-sources>{{ __('ai.inspector_no_sources') }}</p>
        @elseif($status === 'refused')
            {{-- Refus AVANT l'appel : rien n'est parti chez le fournisseur. --}}
            <p class="text-[11px] font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400">{{ __('ai.inspector_state.refused') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-amber-800 dark:text-amber-300" data-inspector-refusal="{{ $result['refusal_reason'] ?? '' }}">{{ __('ai.behavior_sandbox_refused.'.($result['refusal_reason'] ?? 'temporarily_unavailable')) }}</p>
        @else
            <p class="text-[11px] font-medium uppercase tracking-wider text-red-600 dark:text-red-400">{{ __('ai.inspector_state.error') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-red-700 dark:text-red-300" data-inspector-failed>{{ __('ai.behavior_sandbox_failed') }}</p>
        @endif
    </div>

    {{-- ========================= TRACE (4 etapes) ======================= --}}
    <div data-inspector-pane="trace">
        <ol class="relative space-y-0">

            {{-- 1. COMPOSITION — pose par le sandbox depuis PromptRepository
                 et OrganizationAiDoctrine::activeFor(). --}}
            <li class="relative pl-6 pb-4 before:absolute before:left-[5px] before:top-4 before:bottom-0 before:w-px before:bg-gray-200 dark:before:bg-gray-700"
                data-inspector-step="composition" data-inspector-step-state="done">
                <span class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('ai.inspector_trace_step.composition') }}</p>
                <dl class="mt-1.5 space-y-1 text-xs">
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_constitution') }}</dt>
                        <dd class="font-mono text-gray-900 dark:text-gray-100">{{ $value($result['constitution_version'] ?? null) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_doctrine') }}</dt>
                        <dd class="text-right text-gray-900 dark:text-gray-100" data-inspector-doctrine="{{ $result['doctrine_label'] ?? 'none' }}">
                            {{ ($result['doctrine_label'] ?? null) === 'active' ? __('ai.inspector_doctrine_active') : __('ai.inspector_doctrine_none') }}
                        </dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_capability') }}</dt>
                        <dd class="text-right text-gray-900 dark:text-gray-100">{{ $capability === '' ? '—' : __('ai.capability_label.'.$capability) }}</dd>
                    </div>
                </dl>
            </li>

            {{-- 2. CONTEXTE — ContexteBorne, via le DTO. --}}
            <li class="relative pl-6 pb-4 before:absolute before:left-[5px] before:top-4 before:bottom-0 before:w-px before:bg-gray-200 dark:before:bg-gray-700"
                data-inspector-step="context" data-inspector-step-state="{{ ! $contextReached ? 'not_reached' : ($usedSources === [] ? 'done_empty' : 'done') }}">
                <span class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full {{ ! $contextReached ? 'bg-gray-300 dark:bg-gray-600' : ($usedSources === [] ? 'bg-gray-400 dark:bg-gray-500' : 'bg-emerald-500') }}" aria-hidden="true"></span>
                <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('ai.inspector_trace_step.context') }}</p>
                @if(! $contextReached)
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.inspector_trace_not_reached') }}</p>
                @else
                    <p class="mt-1.5 text-xs text-gray-700 dark:text-gray-300" data-inspector-context-counts="{{ count($usedSources) }}/{{ count($deniedSources) }}/{{ count($emptySources) }}">
                        {{ __('ai.inspector_trace_context_counts', ['used' => count($usedSources), 'denied' => count($deniedSources), 'empty' => count($emptySources)]) }}
                    </p>
                @endif
            </li>

            {{-- 3. FOURNISSEUR — lignes du ledger canonique de cette correlation.
                 Zero ligne n'est pas une absence de mesure : c'est la mesure
                 que rien n'est parti. --}}
            <li class="relative pl-6 pb-4 before:absolute before:left-[5px] before:top-4 before:bottom-0 before:w-px before:bg-gray-200 dark:before:bg-gray-700"
                data-inspector-step="provider" data-inspector-step-state="{{ $telemetry === [] ? 'not_reached' : ($status === 'failed' ? 'failed' : 'done') }}">
                <span class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full {{ $telemetry === [] ? 'bg-gray-300 dark:bg-gray-600' : ($status === 'failed' ? 'bg-red-500' : 'bg-emerald-500') }}" aria-hidden="true"></span>
                <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('ai.inspector_trace_step.provider') }}</p>
                @if($telemetry === [])
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" data-inspector-provider-not-reached>{{ __('ai.inspector_trace_provider_not_reached') }}</p>
                @else
                    <ul class="mt-1.5 space-y-1.5 text-xs">
                        @foreach($telemetry as $row)
                            <li class="leading-snug">
                                <span class="font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $value($row['operation']) }}</span>
                                <span class="block break-words text-gray-900 dark:text-gray-100">{{ $value($row['provider']) }} · <span class="font-mono">{{ $value($row['model']) }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>

            {{-- 4. ISSUE — le statut du DTO. Elle existe toujours. --}}
            <li class="relative pl-6" data-inspector-step="issue" data-inspector-step-state="{{ $runState }}">
                <span class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full {{ $runState === 'success' ? 'bg-emerald-500' : ($runState === 'refused' ? 'bg-amber-500' : 'bg-red-500') }}" aria-hidden="true"></span>
                <p class="font-mono text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('ai.inspector_trace_step.issue') }}</p>
                <p class="mt-1.5 inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $stateChip($runState === 'success' ? 'used' : ($runState === 'refused' ? 'denied' : 'error')) }}">
                    {{ __('ai.inspector_state.'.$runState) }}
                </p>
            </li>
        </ol>
    </div>

    {{-- ============================ SOURCES ============================ --}}
    <div data-inspector-pane="sources">
        <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">

            @foreach($usedSources as $sourceName)
                @php $entries = $provenance[$sourceName] ?? []; @endphp
                <li class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-500/25 dark:bg-emerald-500/5"
                    data-inspector-source="{{ $sourceName }}" data-inspector-source-state="used">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $sourceLabel((string) $sourceName) }}</span>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $stateChip('used') }}">{{ __('ai.inspector_source_used') }}</span>
                    </div>
                    <p class="mt-1 font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $sourceName }}</p>
                    @if($entries !== [])
                        <button type="button"
                                class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-sky-700 hover:underline dark:text-sky-400"
                                data-inspector-provenance-open="{{ $sourceName }}"
                                data-inspector-provenance-label="{{ $sourceLabel((string) $sourceName) }}">
                            {{ trans_choice('ai.inspector_provenance_open', count($entries), ['count' => count($entries)]) }}
                        </button>
                    @endif
                </li>
            @endforeach

            @foreach($emptySources as $sourceName)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                    data-inspector-source="{{ $sourceName }}" data-inspector-source-state="empty">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $sourceLabel((string) $sourceName) }}</span>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $stateChip('empty') }}">{{ __('ai.inspector_source_empty') }}</span>
                    </div>
                    <p class="mt-1 font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $sourceName }}</p>
                </li>
            @endforeach

            {{-- La CLE (nom de source) sert d'ancre ; le texte affiche est la
                 RAISON traduite. Jamais le contenu, ni l'identite de ce qui a
                 ete refuse — et aucun declencheur de tiroir, qui suggererait
                 qu'il y a quelque chose a ouvrir. --}}
            @foreach($deniedSources as $sourceName => $reason)
                <li class="rounded-lg border border-amber-200 bg-amber-50/40 p-3 dark:border-amber-500/25 dark:bg-amber-500/5"
                    data-inspector-source="{{ $sourceName }}" data-inspector-source-state="denied" data-inspector-source-reason="{{ $reason }}">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $sourceLabel((string) $sourceName) }}</span>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $stateChip('denied') }}">{{ __('ai.inspector_source_denied') }}</span>
                    </div>
                    <p class="mt-1 font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $sourceName }}</p>
                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ $deniedLabel((string) $reason) }}</p>
                </li>
            @endforeach

            {{-- Le tour s'est arrete avant la construction du contexte : ces
                 sources n'ont pas ete consultees, et surtout elles ne sont pas
                 vides. --}}
            @foreach($notRequestedSources as $sourceName)
                <li class="rounded-lg border border-dashed border-gray-200 p-3 dark:border-gray-700"
                    data-inspector-source="{{ $sourceName }}" data-inspector-source-state="not_requested">
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $sourceLabel((string) $sourceName) }}</span>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $stateChip('neutral') }}">{{ __('ai.inspector_component_state.not_requested') }}</span>
                    </div>
                    <p class="mt-1 font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $sourceName }}</p>
                </li>
            @endforeach

            @if($usedSources === [] && $emptySources === [] && $deniedSources === [] && $notRequestedSources === [])
                <li class="text-sm text-gray-500 dark:text-gray-400" data-inspector-sources-none>{{ __('ai.inspector_sources_none') }}</li>
            @endif
        </ul>

        {{-- Contenu des tiroirs, rendu UNE fois a partir de `$provenance` — et
             `$provenance` est, par construction ci-dessus, incapable de decrire
             une source refusee. Une seule garde, a un seul endroit : deux gardes
             redondantes se couvrent l'une l'autre et aucune ne peut plus etre
             mise a l'epreuve. --}}
        @foreach($provenance as $sourceName => $entries)
            <div hidden data-inspector-provenance="{{ $sourceName }}">
                <ul class="space-y-3">
                    @foreach($entries as $entry)
                        <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700" data-inspector-provenance-entry>
                            <p class="font-mono text-[11px] uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $value($entry['type'] ?? null) }}</p>
                            <p class="mt-0.5 break-all font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $value($entry['id'] ?? null) }}</p>
                            <p class="mt-2 whitespace-pre-wrap text-xs leading-relaxed text-gray-800 dark:text-gray-200">{{ $value($entry['extrait'] ?? null) }}</p>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    {{-- =========================== TELEMETRIE ========================== --}}
    <div data-inspector-pane="telemetry" data-inspector-telemetry data-inspector-telemetry-rows="{{ count($telemetry) }}">
        <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1.5 text-xs">
            <span class="inline-flex items-baseline gap-1.5">
                <span class="text-gray-400 dark:text-gray-500">{{ __('ai.inspector_field_correlation') }}</span>
                <span class="break-all font-mono text-gray-700 dark:text-gray-300" data-inspector-correlation>{{ $value($correlationId) }}</span>
            </span>
            <span class="inline-flex items-baseline gap-1.5">
                <span class="text-gray-400 dark:text-gray-500">{{ __('ai.inspector_field_ledger') }}</span>
                <span class="{{ $ledgerEntries > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-300' }}" data-inspector-ledger-entries="{{ $ledgerEntries }}">
                    {{ $ledgerEntries > 0 ? trans_choice('ai.behavior_sandbox_ledgered', $ledgerEntries, ['count' => $ledgerEntries]) : __('ai.behavior_sandbox_not_ledgered') }}
                </span>
            </span>
        </div>

        @if($telemetry === [])
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-inspector-telemetry-empty>{{ __('ai.inspector_execution_empty') }}</p>
        @else
            <div class="mt-2 space-y-1.5">
                @foreach($telemetry as $row)
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-gray-900/40"
                         data-inspector-invocation="{{ $row['operation'] }}" data-inspector-invocation-status="{{ $row['status'] }}">
                        <span class="font-mono font-medium text-gray-900 dark:text-gray-100">{{ $value($row['operation']) }}</span>
                        <span class="text-gray-600 dark:text-gray-400">{{ $value($row['provider']) }}</span>
                        <span class="font-mono text-gray-600 dark:text-gray-400">{{ $value($row['model']) }}</span>
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-gray-400 dark:text-gray-500">{{ __('ai.inspector_execution_tokens_in') }}</span>
                            <span class="tabular-nums text-gray-800 dark:text-gray-200" data-inspector-input-tokens="{{ $row['input_tokens'] ?? '' }}">{{ $value($row['input_tokens']) }}</span>
                        </span>
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-gray-400 dark:text-gray-500">{{ __('ai.inspector_execution_tokens_out') }}</span>
                            <span class="tabular-nums text-gray-800 dark:text-gray-200" data-inspector-output-tokens="{{ $row['output_tokens'] ?? '' }}">{{ $value($row['output_tokens']) }}</span>
                        </span>
                        {{-- Le cout n'est rendu que lorsque le ledger le dit CONNU
                             (`cost_status`). Une estimation affichee comme un
                             montant serait un chiffre invente. --}}
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-gray-400 dark:text-gray-500">{{ __('ai.inspector_execution_cost') }}</span>
                            <span class="tabular-nums text-gray-800 dark:text-gray-200" data-inspector-cost-status="{{ $row['cost_status'] }}">{{ $row['cost'] === null ? '—' : $row['cost'].' '.$value($row['currency']) }}</span>
                        </span>
                        {{-- En secondes : c'est la precision que portent
                             `started_at`/`completed_at`. --}}
                        <span class="inline-flex items-baseline gap-1">
                            <span class="text-gray-400 dark:text-gray-500">{{ __('ai.inspector_execution_latency') }}</span>
                            <span class="tabular-nums text-gray-800 dark:text-gray-200" data-inspector-latency="{{ $row['latency_seconds'] ?? '' }}">{{ $row['latency_seconds'] === null ? '—' : $row['latency_seconds'].' s' }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_execution_help') }}</p>
            <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400" data-inspector-latency-note>{{ __('ai.inspector_execution_latency_note') }}</p>
        @endif
    </div>
</div>
