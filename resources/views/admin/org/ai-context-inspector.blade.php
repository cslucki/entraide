{{--
    « AI Context Inspector » V0 (TASK-1533) — voir la plomberie REELLE pendant
    qu'elle fonctionne.

    La question part sur le pipeline canonique (`OrganizationDoctrineSandbox`),
    avec la doctrine ACTIVE : ce que l'ecran montre est ce qu'un membre
    recevrait, pas ce que produirait un brouillon. Il n'y a donc aucun second
    moteur, et cette page ne CALCULE rien : elle relit ce que le pipeline et le
    ledger canonique (`ai_provider_invocations`) ont deja ecrit.

    Regles de verite de l'ecran :
      - une valeur absente s'affiche « — », jamais 0 ni une estimation ;
      - une source refusee se nomme par sa RAISON traduite, jamais par son
        contenu — un refus ACL ne doit rien reveler ;
      - une source autorisee restee vide est dite vide, pas « non consultee » ;
      - aucune cle, aucun credential, aucun prompt complet.

    Ce n'est pas une autorite d'administration : rien ne s'y modifie. Les liens
    renvoient vers les surfaces canoniques (doctrine, connaissances).
--}}
@php
    $inspectorCapability = old('capability', $result['capability'] ?? ($capabilities[0] ?? ''));
    $inspectorQuestion = old('question', '');
    $capabilityLabel = static fn (string $id): string => __('ai.capability_label.'.$id);
    $sourceLabel = static function (string $name): string {
        $key = 'ai.inspector_source_label.'.str_replace('.', '_', $name);

        // Une source non encore etiquetee s'affiche sous son identifiant
        // technique : cet ecran est un outil de diagnostic, un nom brut y est
        // plus honnete qu'un libelle invente.
        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $name;
    };
    $deniedLabel = static function (string $reason): string {
        $key = 'ai.behavior_sandbox_source_denied.'.$reason;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : __('ai.behavior_sandbox_source_denied.other');
    };
    $value = static fn ($raw): string => ($raw === null || $raw === '') ? '—' : (string) $raw;

    $usedSources = is_array($result['sources_used'] ?? null) ? $result['sources_used'] : [];
    $deniedSources = is_array($result['sources_denied'] ?? null) ? $result['sources_denied'] : [];
    // Autorisee, ni utilisee ni refusee = consultee, sans rien a dire.
    $emptySources = array_values(array_diff($allowedSources, $usedSources, array_keys($deniedSources)));
    $status = (string) ($result['status'] ?? '');
@endphp

<x-org-admin-layout :title="__('ai.inspector_title')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100" data-inspector-title>{{ __('ai.inspector_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-3xl">{{ __('ai.inspector_intro') }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900" data-inspector-errors>
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-4xl space-y-6">

        {{-- 1. LA QUESTION --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-inspector-form-card>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('ai.inspector_help') }}</p>

            <form method="POST" action="{{ route('organization.admin.ai-context-inspector.run', ['organization' => $organization->slug]) }}" class="space-y-4" data-inspector-form>
                @csrf
                <div>
                    <label for="inspector-capability" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ai.inspector_capability') }}</label>
                    <select id="inspector-capability" name="capability" class="w-full md:w-72 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        @foreach($capabilities as $capabilityId)
                            <option value="{{ $capabilityId }}" @selected($inspectorCapability === $capabilityId)>{{ $capabilityLabel($capabilityId) }}</option>
                        @endforeach
                    </select>
                    @error('capability')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="inspector-question" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ai.inspector_question') }}</label>
                    <textarea id="inspector-question" name="question" rows="3" maxlength="1000" required
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('question') border-red-500 @enderror">{{ $inspectorQuestion }}</textarea>
                    @error('question')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700" data-inspector-run>
                    {{ __('ai.inspector_run') }}
                </button>
            </form>
        </section>

        @if(is_array($result))
            {{-- 2. LA REPONSE --}}
            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-inspector-result data-inspector-status="{{ $status }}" aria-live="polite">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('ai.inspector_answer_title') }}</h2>

                @if($status === 'answered')
                    <div class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900/40 rounded-lg p-3 border border-gray-100 dark:border-gray-700" data-inspector-answer>{{ $result['answer'] ?? '' }}</div>
                @elseif($status === 'refused')
                    {{-- Refus AVANT l'appel : rien n'est parti chez le fournisseur. --}}
                    <p class="text-sm text-amber-700 dark:text-amber-300" data-inspector-refusal="{{ $result['refusal_reason'] ?? '' }}">{{ __('ai.behavior_sandbox_refused.'.($result['refusal_reason'] ?? 'temporarily_unavailable')) }}</p>
                @elseif($status === 'no_sources')
                    <p class="text-sm text-gray-700 dark:text-gray-300" data-inspector-no-sources>{{ __('ai.inspector_no_sources') }}</p>
                @else
                    <p class="text-sm text-red-700 dark:text-red-300" data-inspector-failed>{{ __('ai.behavior_sandbox_failed') }}</p>
                @endif

                <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs">
                    <div class="flex justify-between gap-3 py-1 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_capability') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 text-right" data-inspector-capability="{{ $result['capability'] ?? '' }}">{{ isset($result['capability']) ? $capabilityLabel((string) $result['capability']) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-1 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_scope') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 text-right" data-inspector-scope="{{ $result['scope'] ?? '' }}">{{ __('ai.behavior_sandbox_scope_organization') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-1 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_constitution') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 text-right font-mono">{{ $value($result['constitution_version'] ?? null) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-1 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_doctrine') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 text-right" data-inspector-doctrine="{{ $result['doctrine_label'] ?? 'none' }}">
                            {{ ($result['doctrine_label'] ?? null) === 'active' ? __('ai.inspector_doctrine_active') : __('ai.inspector_doctrine_none') }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3 py-1 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_correlation') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 text-right font-mono break-all" data-inspector-correlation>{{ $value($result['correlation_id'] ?? null) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 py-1 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.inspector_field_ledger') }}</dt>
                        @php $ledgerEntries = (int) ($result['ledger_entries'] ?? 0); @endphp
                        <dd class="text-right {{ $ledgerEntries > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-gray-100' }}" data-inspector-ledger-entries="{{ $ledgerEntries }}">
                            {{ $ledgerEntries > 0 ? trans_choice('ai.behavior_sandbox_ledgered', $ledgerEntries, ['count' => $ledgerEntries]) : __('ai.behavior_sandbox_not_ledgered') }}
                        </dd>
                    </div>
                </dl>
            </section>

            {{-- 3. CONTEXTE / SOURCES --}}
            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-inspector-sources>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('ai.inspector_sources_title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('ai.inspector_sources_help') }}</p>

                <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                    @foreach($usedSources as $sourceName)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-3 py-2" data-inspector-source="{{ $sourceName }}" data-inspector-source-state="used">
                            <span class="text-gray-900 dark:text-gray-100">{{ $sourceLabel((string) $sourceName) }}</span>
                            <span class="shrink-0 text-xs text-emerald-600 dark:text-emerald-400">✓ {{ __('ai.inspector_source_used') }}</span>
                        </li>
                    @endforeach

                    @foreach($emptySources as $sourceName)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-3 py-2" data-inspector-source="{{ $sourceName }}" data-inspector-source-state="empty">
                            <span class="text-gray-700 dark:text-gray-300">{{ $sourceLabel((string) $sourceName) }}</span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">— {{ __('ai.inspector_source_empty') }}</span>
                        </li>
                    @endforeach

                    {{-- La CLE (nom de source) sert d'ancre de test ; le texte
                         affiche est la RAISON traduite. Jamais le contenu, ni
                         l'identite de ce qui a ete refuse. --}}
                    @foreach($deniedSources as $sourceName => $reason)
                        <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-3 py-2" data-inspector-source="{{ $sourceName }}" data-inspector-source-state="denied" data-inspector-source-reason="{{ $reason }}">
                            <span class="text-gray-700 dark:text-gray-300">{{ $sourceLabel((string) $sourceName) }}</span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">× {{ $deniedLabel((string) $reason) }}</span>
                        </li>
                    @endforeach

                    @if($usedSources === [] && $emptySources === [] && $deniedSources === [])
                        <li class="py-2 text-gray-500 dark:text-gray-400" data-inspector-sources-none>{{ __('ai.inspector_sources_none') }}</li>
                    @endif
                </ul>
            </section>

            {{-- 4. EXECUTION (ledger canonique) --}}
            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-inspector-telemetry data-inspector-telemetry-rows="{{ count($telemetry) }}">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('ai.inspector_execution_title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('ai.inspector_execution_help') }}</p>

                @if($telemetry === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400" data-inspector-telemetry-empty>{{ __('ai.inspector_execution_empty') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.inspector_execution_operation') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.inspector_execution_provider') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.inspector_execution_model') }}</th>
                                    <th class="py-1 pr-4 font-medium text-right">{{ __('ai.inspector_execution_tokens_in') }}</th>
                                    <th class="py-1 pr-4 font-medium text-right">{{ __('ai.inspector_execution_tokens_out') }}</th>
                                    <th class="py-1 pr-4 font-medium text-right">{{ __('ai.inspector_execution_cost') }}</th>
                                    <th class="py-1 font-medium text-right">{{ __('ai.inspector_execution_latency') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($telemetry as $row)
                                    <tr class="border-t border-gray-100 dark:border-gray-700 text-gray-700 dark:text-gray-300" data-inspector-invocation="{{ $row['operation'] }}" data-inspector-invocation-status="{{ $row['status'] }}">
                                        <td class="py-1 pr-4 font-mono">{{ $value($row['operation']) }}</td>
                                        <td class="py-1 pr-4">{{ $value($row['provider']) }}</td>
                                        <td class="py-1 pr-4 font-mono">{{ $value($row['model']) }}</td>
                                        <td class="py-1 pr-4 text-right tabular-nums" data-inspector-input-tokens="{{ $row['input_tokens'] ?? '' }}">{{ $value($row['input_tokens']) }}</td>
                                        <td class="py-1 pr-4 text-right tabular-nums" data-inspector-output-tokens="{{ $row['output_tokens'] ?? '' }}">{{ $value($row['output_tokens']) }}</td>
                                        {{-- Le cout n'est rendu que lorsque le ledger le dit CONNU
                                             (`cost_status`). Une estimation affichee comme un
                                             montant serait un chiffre invente. --}}
                                        <td class="py-1 pr-4 text-right tabular-nums" data-inspector-cost-status="{{ $row['cost_status'] }}">
                                            {{ $row['cost'] === null ? '—' : $row['cost'].' '.$value($row['currency']) }}
                                        </td>
                                        {{-- En secondes : c'est la precision que portent
                                             `started_at`/`completed_at`. --}}
                                        <td class="py-1 text-right tabular-nums" data-inspector-latency="{{ $row['latency_seconds'] ?? '' }}">{{ $row['latency_seconds'] === null ? '—' : $row['latency_seconds'].' s' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-inspector-latency-note>{{ __('ai.inspector_execution_latency_note') }}</p>
                @endif
            </section>
        @endif

        {{-- 5. AUTORITES CANONIQUES — des liens, jamais un second point de reglage. --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-inspector-authorities>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('ai.inspector_authorities_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('ai.inspector_authorities_help') }}</p>
            <ul class="text-sm space-y-1">
                <li><a class="text-blue-600 dark:text-blue-400 hover:underline" href="{{ route('organization.admin.ai-behavior', ['organization' => $organization->slug]) }}" data-inspector-link="behavior">{{ __('ai.behavior_title') }}</a></li>
                <li><a class="text-blue-600 dark:text-blue-400 hover:underline" href="{{ route('organization.admin.ai-knowledge', ['organization' => $organization->slug]) }}" data-inspector-link="knowledge">{{ __('navigation.org_admin_ai_knowledge') }}</a></li>
                <li><a class="text-blue-600 dark:text-blue-400 hover:underline" href="{{ route('organization.admin.ai-cockpit', ['organization' => $organization->slug]) }}" data-inspector-link="cockpit">{{ __('ai.cockpit_title') }}</a></li>
            </ul>
        </section>
    </div>
</x-org-admin-layout>
