@php
    // TASK-1581 — Inspector UI V0. Document Blade autonome : aucun Alpine,
    // aucun JS, aucun bouton d'action. La page rend `$trace` — la sortie du
    // MEME lecteur que `ai:inspect-turn --json` — et sa projection ; `null`
    // s'affiche « UNAVAILABLE », jamais vide ni zero.
    $aff = static fn ($v): string => match (true) {
        $v === null => 'UNAVAILABLE',
        is_bool($v) => $v ? 'true' : 'false',
        is_scalar($v) => (string) $v,
        default => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    };
    $label = static fn (?string $l): string => match ($l) {
        'MEASURED' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        'DERIVED' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
        'DECLARED' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        default => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    };
    $truth = $trace['truth'] ?? [];
    $decision = $trace['decision'] ?? [];
    $statutClasse = match ($decision['status'] ?? null) {
        'answered' => 'text-green-600 dark:text-green-400',
        'abstained', 'refused' => 'text-amber-600 dark:text-amber-400',
        'failed' => 'text-red-600 dark:text-red-400',
        default => 'text-gray-500 dark:text-gray-400',
    };
    $projection = $trace['projection'] ?? null;
@endphp
<x-admin-layout title="Inspector IA — tour">
    <div class="max-w-6xl mx-auto space-y-6" data-inspector-turn>

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Tour <span class="font-mono text-base">{{ $aff($trace['run']['turn_id'] ?? null) }}</span>
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Organization <strong>{{ $organization?->slug ?? 'UNAVAILABLE' }}</strong> · support <code>{{ $support }}</code> · {{ $aff($trace['run']['created_at'] ?? null) }}
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 font-mono">ai_interaction_id {{ $aff($trace['run']['ai_interaction_id'] ?? null) }}</p>
            </div>
            <a href="{{ route('admin.ai-turns') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">← Inspector</a>
        </div>

        {{-- Verdict --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4" data-inspector-decision>
            @foreach (['status' => 'Verdict', 'stage' => 'Étape terminale', 'reason_code' => 'Code', 'decided_by' => 'Décidé par', 'latency_ms' => 'Latence (ms)'] as $cle => $titre)
                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ $titre }}</p>
                    <p class="text-sm font-medium mt-1 font-mono {{ $cle === 'status' ? $statutClasse : 'text-gray-900 dark:text-gray-100' }}">{{ $aff($decision[$cle] ?? null) }}</p>
                    <span class="inline-block mt-1 text-[10px] px-1.5 py-0.5 rounded {{ $label($truth['decision.'.$cle] ?? null) }}">{{ $truth['decision.'.$cle] ?? 'UNAVAILABLE' }}</span>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Identité --}}
            <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-inspector-identity>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Identité</h3>
                @if (($trace['identity'] ?? null) === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400">UNAVAILABLE — ce tour n'a pas déposé son identité (antérieur au V0).</p>
                @else
                    <dl class="text-sm space-y-1">
                        @foreach ($trace['identity'] as $cle => $valeur)
                            <div class="flex items-baseline gap-2">
                                <dt class="w-40 shrink-0 text-gray-500 dark:text-gray-400">{{ $cle }}</dt>
                                <dd class="font-mono text-gray-900 dark:text-gray-100 break-all">{{ $aff($valeur) }}</dd>
                                <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded {{ $label($truth['identity.'.$cle] ?? null) }}">{{ $truth['identity.'.$cle] ?? 'UNAVAILABLE' }}</span>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>

            {{-- État & historique --}}
            <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-inspector-state>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">État (3 axes) et historique vu</h3>
                <dl class="text-sm space-y-1">
                    @foreach (($trace['state'] ?? []) as $cle => $valeur)
                        <div class="flex items-baseline gap-2">
                            <dt class="w-40 shrink-0 text-gray-500 dark:text-gray-400">state.{{ $cle }}</dt>
                            <dd class="font-mono text-gray-900 dark:text-gray-100">{{ $aff($valeur) }}</dd>
                            <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded {{ $label($truth['state.'.$cle] ?? null) }}">{{ $truth['state.'.$cle] ?? 'UNAVAILABLE' }}</span>
                        </div>
                    @endforeach
                    @if (($trace['history'] ?? null) === null)
                        <div class="pt-2 text-gray-500 dark:text-gray-400">history — UNAVAILABLE</div>
                    @else
                        @foreach ($trace['history'] as $cle => $valeur)
                            <div class="flex items-baseline gap-2">
                                <dt class="w-40 shrink-0 text-gray-500 dark:text-gray-400">history.{{ $cle }}</dt>
                                <dd class="font-mono text-gray-900 dark:text-gray-100 break-all">{{ $aff($valeur) }}</dd>
                            </div>
                        @endforeach
                    @endif
                </dl>
            </section>
        </div>

        {{-- Étapes --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden" data-inspector-steps>
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Étapes (ordre réel)</h3>
                <span class="text-[10px] px-1.5 py-0.5 rounded {{ $label($truth['steps'] ?? null) }}">{{ $truth['steps'] ?? 'UNAVAILABLE' }}</span>
            </div>
            @if (($trace['steps'] ?? null) === null)
                <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">UNAVAILABLE — aucune étape déposée.</p>
            @else
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr><th class="px-4 py-2 text-left">Étape</th><th class="px-4 py-2 text-left">Statut</th><th class="px-4 py-2 text-left">Code</th><th class="px-4 py-2 text-left">Mesures</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($trace['steps'] as $etape)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $etape['name'] ?? '?' }}</td>
                                <td class="px-4 py-2">{{ $etape['status'] ?? '?' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $etape['reason_code'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-600 dark:text-gray-300">{{ isset($etape['metrics']) ? $aff($etape['metrics']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Sources (4 familles) --}}
            <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-inspector-sources>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Sources — quatre familles</h3>
                @if (($trace['sources'] ?? null) === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400">UNAVAILABLE — ce chemin n'écrit aucune famille (mode ia, ou tour antérieur).</p>
                @else
                    <dl class="text-sm space-y-1">
                        @foreach ($trace['sources'] as $cle => $valeur)
                            <div class="flex items-baseline gap-2">
                                <dt class="w-24 shrink-0 text-gray-500 dark:text-gray-400">{{ $cle }}</dt>
                                <dd class="font-mono text-xs text-gray-900 dark:text-gray-100 break-all">{{ $aff($valeur) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
                @if (($trace['retrieval_trace'] ?? null) !== null)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">retrieval_trace : candidats {{ $aff($trace['retrieval_trace']['dense_candidates_count']) }} · après filtre {{ $aff($trace['retrieval_trace']['after_distance_filter_count']) }} · rerank tenté {{ $aff($trace['retrieval_trace']['rerank_attempted']) }} · contexte final {{ $aff($trace['retrieval_trace']['final_context_count']) }}</p>
                @endif
            </section>

            {{-- Projection --}}
            <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-inspector-projection>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Projection — ce que le membre a vu</h3>
                @if ($projection === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400">UNAVAILABLE — aucune interaction à projeter (tour zéro-provider).</p>
                @else
                    <dl class="text-sm space-y-2">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Question <span class="text-[10px] px-1.5 py-0.5 rounded {{ $label($projection['truth']['question'] ?? null) }}">{{ $projection['truth']['question'] ?? 'UNAVAILABLE' }}</span></dt>
                            <dd class="text-gray-900 dark:text-gray-100" data-inspector-question>{{ $aff($projection['question']) }}@if ($projection['question'] === null) <span class="text-xs text-gray-400">({{ $projection['unavailable_reasons']['question'] ?? '' }})</span>@endif</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Sources vues <span class="text-[10px] px-1.5 py-0.5 rounded {{ $label($projection['truth']['sources'] ?? null) }}">{{ $projection['truth']['sources'] ?? 'UNAVAILABLE' }}</span></dt>
                            <dd>
                                @if ($projection['sources'] === null)
                                    <span class="text-gray-500 dark:text-gray-400">UNAVAILABLE ({{ $projection['unavailable_reasons']['sources'] ?? '' }})</span>
                                @elseif ($projection['sources'] === [])
                                    <span class="text-gray-500 dark:text-gray-400">(aucune)</span>
                                @else
                                    <ul class="list-disc pl-5 space-y-1">
                                        @foreach ($projection['sources'] as $source)
                                            <li><span class="font-mono text-xs">[{{ $source['ref'] ?? '?' }}]</span> {{ $source['title'] ?? $source['dossier_name'] ?? 'UNAVAILABLE' }} <span class="text-xs text-gray-400">{{ $source['type'] ?? '' }}</span>
                                                @if (! empty($source['excerpt'])) <div class="text-xs text-gray-600 dark:text-gray-300 italic">« {{ \Illuminate\Support\Str::limit((string) $source['excerpt'], 160) }} »</div> @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Chunks consultés <span class="text-[10px] px-1.5 py-0.5 rounded {{ $label($projection['truth']['chunks'] ?? null) }}">{{ $projection['truth']['chunks'] ?? 'UNAVAILABLE' }}</span></dt>
                            <dd>
                                @if ($projection['chunks'] === [])
                                    <span class="text-gray-500 dark:text-gray-400">UNAVAILABLE ({{ $projection['unavailable_reasons']['chunks'] ?? '' }})</span>
                                @else
                                    <table class="min-w-full text-xs mt-1">
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            @foreach ($projection['chunks'] as $chunk)
                                                <tr>
                                                    <td class="py-1 font-mono">{{ \Illuminate\Support\Str::limit($chunk['chunk_id'], 13, '…') }}</td>
                                                    <td class="py-1">{{ $chunk['cited'] ? 'cité' : 'consulté' }}</td>
                                                    <td class="py-1 font-mono">{{ $aff($chunk['source_type']) }}</td>
                                                    <td class="py-1 text-gray-400">{{ $chunk['unavailable_reason'] ?? '' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">consulted_not_cited : {{ $projection['consulted_not_cited'] === null ? 'UNAVAILABLE' : count($projection['consulted_not_cited']) }}</p>
                                @endif
                            </dd>
                        </div>
                    </dl>
                @endif
            </section>
        </div>

        {{-- Réponse (jamais le prompt) --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-inspector-output>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Réponse finale</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">failure : <span class="font-mono">{{ $aff($trace['output']['failure'] ?? null) }}</span> · grounded : <span class="font-mono">{{ $aff($trace['output']['grounded'] ?? null) }}</span></p>
            <pre class="whitespace-pre-wrap text-sm text-gray-900 dark:text-gray-100 bg-gray-50 dark:bg-gray-900/50 rounded p-3 max-h-64 overflow-auto">{{ $aff($trace['output']['response'] ?? null) }}</pre>
        </section>

        {{-- Shell --}}
        @if (($trace['shell'] ?? null) !== null)
            <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-inspector-shell>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Shell — ligne assistant et déclins</h3>
                <p class="text-xs font-mono text-gray-600 dark:text-gray-300">{{ $trace['shell']['message_id'] }} · producer {{ $aff($trace['shell']['producer']) }}</p>
                @if ($trace['shell']['fallthroughs'] === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">fallthroughs — UNAVAILABLE (ligne antérieure à V0-I)</p>
                @elseif ($trace['shell']['fallthroughs'] === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Aucune branche n'a décliné avant celle-ci.</p>
                @else
                    <ol class="list-decimal pl-5 text-sm mt-2 space-y-0.5">
                        @foreach ($trace['shell']['fallthroughs'] as $declin)
                            <li><span class="font-mono">{{ $declin['branch'] ?? '?' }}</span> — {{ $declin['status'] ?? '?' }} <span class="font-mono text-xs">{{ $declin['reason_code'] ?? '' }}</span></li>
                        @endforeach
                    </ol>
                @endif
            </section>
        @endif

        {{-- Conversation --}}
        @if ($conversation !== null)
            <section class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden" data-inspector-conversation>
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Conversation ({{ $conversation['strategy'] }}) — ce que chaque tour a vu</h3>
                    @if ($conversation['stopped'] !== null)
                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Chaîne arrêtée : {{ $conversation['stopped']['reason_code'] }}</p>
                    @endif
                </div>
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 uppercase text-gray-500 dark:text-gray-400">
                        <tr><th class="px-4 py-2 text-left">#</th><th class="px-4 py-2 text-left">Chemin</th><th class="px-4 py-2 text-left">Verdict</th><th class="px-4 py-2 text-left">Vu la réponse précédente</th><th class="px-4 py-2 text-left">Mode changé</th><th class="px-4 py-2 text-left">ContextBuilder changé</th><th class="px-4 py-2 text-left"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($conversation['turns'] as $tour)
                            <tr @class(['bg-indigo-50 dark:bg-indigo-900/20' => ($tour['ai_interaction_id'] ?? null) === ($trace['run']['ai_interaction_id'] ?? '__')])>
                                <td class="px-4 py-2">{{ $tour['position'] }}</td>
                                <td class="px-4 py-2 font-mono">{{ $aff($tour['execution_path']) }}</td>
                                <td class="px-4 py-2">{{ $aff($tour['status']) }}</td>
                                <td class="px-4 py-2 font-mono">{{ $tour['derived']['PREVIOUS_AI_ANSWER_VISIBLE'] }}</td>
                                <td class="px-4 py-2 font-mono">{{ $tour['derived']['MODE_CHANGED'] }}</td>
                                <td class="px-4 py-2 font-mono">{{ $tour['derived']['CONTEXT_BUILDER_CHANGED'] }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($tour['ai_interaction_id'] !== null)
                                        <a href="{{ route('admin.ai-turns.show', ['interaction' => $tour['ai_interaction_id']]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Lire</a>
                                    @elseif ($conversation['strategy'] === 'shell_thread')
                                        <a href="{{ route('admin.ai-turns.shell', ['shellMessage' => $tour['message_id']]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Lire</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <p class="text-xs text-gray-400 dark:text-gray-500">
            Légende des labels : MEASURED = observé pendant le tour · DERIVED = calculé par le lecteur · DECLARED = registre / appelant · UNAVAILABLE = la trace ne le porte pas. Le prompt brut et le texte des chunks ne sont jamais rendus.
        </p>
    </div>
</x-admin-layout>
