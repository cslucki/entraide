{{--
    « Comportement IA » (TASK-1227) — ce qui guide chaque reponse de l'IA dans
    l'Organization, dans l'ordre de la cascade :

        Constitution BouclePro (plateforme, lecture seule)
        -> Doctrine de l'Organization (editable, versionnee)
        -> instruction de la fonction -> contexte autorise -> demande.

    Regles : aucune cle affichee ; la doctrine est du texte utilisateur, elle
    ne peut rien relacher (le code applique tenant, sources, validation
    humaine) ; le bac a sable emet un appel IA REEL comptabilise, sans rien
    activer ni creer.
--}}
@php
    $doctrineBody = old('body', $doctrine?->body ?? '');
    $sandboxCapability = old('capability', $sandboxCapabilities[0] ?? '');
    $sandboxQuestion = old('question', '');
    $capabilityLabel = static fn (string $id): string => __('ai.capability_label.'.$id);
@endphp

<x-org-admin-layout :title="__('ai.behavior_title')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100" data-behavior-title>{{ __('ai.behavior_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-3xl">{{ __('ai.behavior_intro') }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900" data-behavior-errors>
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-4xl space-y-6">

        {{-- CASCADE --}}
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400" data-behavior-cascade aria-label="{{ __('ai.behavior_cascade_title') }}">
            <li class="font-medium text-gray-900 dark:text-gray-100">{{ __('ai.behavior_cascade_constitution') }}</li>
            <li aria-hidden="true">→</li>
            {{-- TASK-1348 : la Constitution de l'Organization s'intercale ici —
                 sous celle de la plateforme, au-dessus de la doctrine. --}}
            <li class="font-medium text-gray-900 dark:text-gray-100">{{ __('ai.behavior_cascade_org_constitution') }}</li>
            <li aria-hidden="true">→</li>
            <li class="font-medium text-gray-900 dark:text-gray-100">{{ __('ai.behavior_cascade_doctrine') }}</li>
            <li aria-hidden="true">→</li>
            <li>{{ __('ai.behavior_cascade_capability') }}</li>
            <li aria-hidden="true">→</li>
            <li>{{ __('ai.behavior_cascade_context') }}</li>
            <li aria-hidden="true">→</li>
            <li>{{ __('ai.behavior_cascade_request') }}</li>
        </ol>

        {{-- 1. CONSTITUTION (lecture seule) --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-behavior-constitution>
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.behavior_constitution_title') }} <span class="text-gray-400 font-normal text-sm">{{ $constitutionVersion }}</span></h2>
                <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200" data-behavior-constitution-badge @if($constitutionIsSeed ?? false) data-behavior-constitution-source="seed" @else data-behavior-constitution-source="published" @endif>{{ ($constitutionIsSeed ?? false) ? __('ai.behavior_constitution_seed_badge') : __('ai.behavior_constitution_badge') }}</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('ai.behavior_constitution_help') }}</p>
            <pre class="whitespace-pre-wrap font-sans text-sm text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900/40 rounded-lg p-4 border border-gray-100 dark:border-gray-700" data-behavior-constitution-text>{{ $constitutionText }}</pre>
        </section>

        {{-- 2. CONSTITUTION DE VOTRE ORGANIZATION (editable, optionnelle) --}}
        {{-- TASK-1348. Volontairement distincte de la Doctrine juste en dessous :
             teinte differente, question differente en tete de bloc. La
             Constitution repond a « qui sommes-nous et quels principes
             fondamentaux gouvernent notre IA ? » ; la Doctrine a « comment
             voulons-nous que l'IA se comporte dans notre metier ? ». Trois
             textarea identiques auraient rendu la hierarchie illisible. --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-800/60 p-6" data-behavior-org-constitution>
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.behavior_org_constitution_title') }}</h2>
                @if($orgConstitution)
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300" data-behavior-org-constitution-status="active" data-behavior-org-constitution-version="{{ $orgConstitution->version }}">{{ __('ai.behavior_org_constitution_active', ['version' => $orgConstitution->version]) }}</span>
                @else
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-behavior-org-constitution-status="none">{{ __('ai.behavior_org_constitution_badge') }}</span>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('ai.behavior_org_constitution_help') }}</p>
            {{-- TASK-1348 : dire ce qui est DEJA herite evite le reflexe de
                 recopier la Constitution plateforme dans le champ ci-dessous —
                 ce serait du bruit compose deux fois dans chaque prompt. --}}
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4" data-behavior-org-constitution-inherit-note>{{ __('ai.behavior_org_constitution_inherit_note') }}</p>

            @unless($orgConstitution)
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4" data-behavior-org-constitution-empty>{{ __('ai.behavior_org_constitution_none') }}</p>
            @endunless

            <form method="POST" action="{{ route('organization.admin.ai-behavior.constitution.update', ['organization' => $organization->slug]) }}">
                @csrf
                @method('PUT')
                {{-- Le champ s'appelle `constitution_body`, PAS `body`.
                     La doctrine juste en dessous utilise `body` : deux champs
                     de meme nom sur la meme page partagent `old()`. Apres le
                     PRG du bac a sable, le texte de DOCTRINE serait revenu
                     dans CE textarea, et un clic sur « Publier » aurait publie
                     la doctrine comme Constitution. Les deux noms sont donc
                     disjoints, du formulaire jusqu'a la validation. --}}
                {{-- Le placeholder est une GUIDANCE, jamais une valeur : un
                     attribut `placeholder` n'est pas soumis, donc rien ne peut
                     l'enregistrer par inadvertance. Le champ reste requis. --}}
                <textarea name="constitution_body" rows="7" maxlength="{{ $orgConstitutionMaxChars }}"
                          placeholder="{{ __('ai.behavior_org_constitution_placeholder') }}"
                          class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                          data-behavior-org-constitution-input>{{ old('constitution_body', $orgConstitution->body ?? '') }}</textarea>
                @error('constitution_body')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700" data-behavior-org-constitution-save>{{ __('ai.behavior_org_constitution_save') }}</button>
                    <span class="text-xs text-gray-400">{{ $orgConstitutionMaxChars }}</span>
                </div>
            </form>

            @if($orgConstitution)
                <form method="POST" action="{{ route('organization.admin.ai-behavior.constitution.withdraw', ['organization' => $organization->slug]) }}" class="mt-3">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs text-gray-500 hover:text-red-600 dark:text-gray-400 underline" data-behavior-org-constitution-withdraw>{{ __('ai.behavior_org_constitution_withdraw') }}</button>
                </form>
            @endif

            @if($orgConstitutionHistoryTotal > 0)
                <div class="mt-5 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('ai.behavior_org_constitution_history') }}</h3>
                    <ul class="space-y-1 text-xs text-gray-500 dark:text-gray-400" data-behavior-org-constitution-history data-behavior-org-constitution-history-total="{{ $orgConstitutionHistoryTotal }}">
                        @foreach($orgConstitutionHistory as $version)
                            <li data-behavior-org-constitution-history-version="{{ $version->version }}">
                                {{ __('ai.constitution_admin_version_by', [
                                    'version' => $version->version,
                                    'author' => $version->author?->publicDisplayName() ?? '—',
                                    'date' => optional($version->activated_at ?? $version->created_at)->isoFormat('LLL'),
                                ]) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- 3. DOCTRINE DE L'ORGANIZATION (editable) --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-behavior-doctrine>
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.behavior_doctrine_title') }}</h2>
                @if($doctrine)
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300" data-behavior-doctrine-status="active" data-behavior-doctrine-version="{{ $doctrine->version }}">{{ __('ai.behavior_doctrine_active', ['version' => $doctrine->version]) }}</span>
                @else
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-behavior-doctrine-status="none">{{ __('ai.cockpit_behavior_doctrine_none') }}</span>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('ai.behavior_doctrine_help') }}</p>

            @if($doctrine)
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4" data-behavior-doctrine-meta>
                    {{ __('ai.behavior_doctrine_active_meta', ['author' => $doctrine->author?->name ?? '—', 'date' => $doctrine->activated_at?->format('d/m/Y H:i') ?? '—']) }}
                </p>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4" data-behavior-doctrine-empty>{{ __('ai.behavior_doctrine_none') }}</p>
            @endif

            <form method="POST" action="{{ route('organization.admin.ai-behavior.doctrine.update', ['organization' => $organization->slug]) }}"
                  x-data="{ body: @js($doctrineBody), max: {{ (int) $doctrineMaxChars }} }" data-behavior-doctrine-form>
                @csrf
                @method('PUT')
                <label for="doctrine-body" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ai.behavior_doctrine_label') }}</label>
                <textarea id="doctrine-body" name="body" rows="7" maxlength="{{ (int) $doctrineMaxChars }}" x-model="body"
                          placeholder="{{ __('ai.behavior_doctrine_placeholder') }}"
                          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('body') border-red-500 @enderror"></textarea>
                <div class="flex flex-wrap items-center justify-between gap-2 mt-1">
                    <p class="text-xs text-gray-400" data-behavior-doctrine-counter x-text="@js(__('ai.behavior_doctrine_chars', ['count' => '__C__', 'max' => '__M__'])).replace('__C__', body.length).replace('__M__', max)"></p>
                    @error('body')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-wrap items-center gap-3 mt-4">
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color: var(--bp-primary)" data-behavior-doctrine-save>
                        {{ __('ai.behavior_doctrine_save') }}
                    </button>
                </div>
            </form>

            @if($doctrine)
                <form method="POST" action="{{ route('organization.admin.ai-behavior.doctrine.withdraw', ['organization' => $organization->slug]) }}"
                      class="mt-3" x-data="{ confirming: false }" data-behavior-doctrine-withdraw-form>
                    @csrf
                    @method('DELETE')
                    <button type="button" x-show="!confirming" @click="confirming = true"
                            class="text-sm text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:underline" data-behavior-doctrine-withdraw>
                        {{ __('ai.behavior_doctrine_withdraw') }}
                    </button>
                    <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">{{ __('ai.behavior_doctrine_withdraw_confirm') }}</span>
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-medium" data-behavior-doctrine-withdraw-confirm>{{ __('ai.behavior_doctrine_withdraw') }}</button>
                        <button type="button" @click="confirming = false" class="text-xs text-gray-500 hover:underline">{{ __('ai.behavior_doctrine_withdraw_cancel') }}</button>
                    </div>
                </form>
            @endif

            <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="rounded-lg border border-gray-100 dark:border-gray-700 p-4" data-behavior-used-by>
                    <p class="font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('ai.behavior_used_by') }}</p>
                    <ul class="space-y-1">
                        @foreach($coveredCapabilities as $capability)
                            <li class="flex items-center gap-2 text-gray-700 dark:text-gray-300" data-behavior-used-by-capability="{{ $capability->id }}">
                                <span class="text-emerald-600 dark:text-emerald-400" aria-hidden="true">✓</span>
                                <span>{{ $capabilityLabel($capability->id) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="rounded-lg border border-amber-100 dark:border-amber-900/40 bg-amber-50/60 dark:bg-amber-900/10 p-4" data-behavior-doctrine-limits>
                    <p class="font-medium text-amber-800 dark:text-amber-200 mb-1">{{ __('ai.behavior_doctrine_limits_title') }}</p>
                    <p class="text-xs text-amber-800/90 dark:text-amber-200/90">{{ __('ai.behavior_doctrine_limits') }}</p>
                </div>
            </div>

            <details class="mt-5 text-sm" data-behavior-history>
                <summary class="cursor-pointer text-gray-700 dark:text-gray-300 font-medium">{{ __('ai.behavior_history_title') }} ({{ $doctrineHistoryTotal }})</summary>
                @if($doctrineHistory->isEmpty())
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{ __('ai.behavior_history_empty') }}</p>
                @else
                    <div class="overflow-x-auto mt-2">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400">
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.behavior_history_version') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.behavior_history_status') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.behavior_history_author') }}</th>
                                    <th class="py-1 pr-4 font-medium">{{ __('ai.behavior_history_date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($doctrineHistory as $version)
                                    <tr class="border-t border-gray-100 dark:border-gray-700 text-gray-700 dark:text-gray-300" data-behavior-history-row="{{ $version->version }}" data-behavior-history-status="{{ $version->status }}">
                                        <td class="py-1 pr-4 font-mono">v{{ $version->version }}</td>
                                        <td class="py-1 pr-4">{{ $version->isActive() ? __('ai.behavior_history_status_active') : __('ai.behavior_history_status_superseded') }}</td>
                                        <td class="py-1 pr-4">{{ $version->author?->name ?? '—' }}</td>
                                        <td class="py-1 pr-4 font-mono">{{ $version->activated_at?->format('d/m/Y H:i') ?? $version->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </details>
        </section>

        {{-- 3. TESTER SANS PUBLIER (appel reel) --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-behavior-sandbox>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('ai.behavior_sandbox_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('ai.behavior_sandbox_help') }}</p>

            <form method="POST" action="{{ route('organization.admin.ai-behavior.sandbox', ['organization' => $organization->slug]) }}" class="space-y-4" data-behavior-sandbox-form>
                @csrf
                {{-- Le brouillon teste = le texte du champ doctrine ci-dessus, recopie a l'envoi. --}}
                <input type="hidden" name="body" value="" data-behavior-sandbox-body>
                {{-- TASK-1348 : le texte de Constitution en cours de saisie part
                     avec l'essai, exactement comme celui de la doctrine. --}}
                <input type="hidden" name="constitution_body" value="" data-behavior-sandbox-constitution-body>
                <div>
                    <label for="sandbox-capability" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ai.behavior_sandbox_capability') }}</label>
                    <select id="sandbox-capability" name="capability" class="w-full md:w-72 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        @foreach($sandboxCapabilities as $capabilityId)
                            <option value="{{ $capabilityId }}" @selected($sandboxCapability === $capabilityId)>{{ $capabilityLabel($capabilityId) }}</option>
                        @endforeach
                    </select>
                    @error('capability')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="sandbox-question" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('ai.behavior_sandbox_question') }}</label>
                    <textarea id="sandbox-question" name="question" rows="3" maxlength="1000" required
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('question') border-red-500 @enderror">{{ $sandboxQuestion }}</textarea>
                    @error('question')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700" data-behavior-sandbox-run>
                    {{ __('ai.behavior_sandbox_run') }}
                </button>
            </form>

            @if(is_array($sandboxResult))
                @php
                    $sr = $sandboxResult;
                    $srStatus = (string) ($sr['status'] ?? '');
                @endphp
                <div class="mt-6 rounded-lg border border-gray-200 dark:border-gray-700 p-4" data-behavior-sandbox-result data-behavior-sandbox-status="{{ $srStatus }}" aria-live="polite">
                    <p class="font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('ai.behavior_sandbox_result_title') }}</p>

                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.behavior_sandbox_guided_by') }}</p>
                    <ul class="text-xs text-gray-700 dark:text-gray-300 space-y-0.5 mb-3" data-behavior-sandbox-guided>
                        <li>{{ __('ai.behavior_sandbox_constitution', ['version' => $sr['constitution_version'] ?? '—']) }}</li>
                        <li data-behavior-sandbox-doctrine="{{ $sr['doctrine_label'] ?? 'none' }}">{{ ($sr['doctrine_label'] ?? null) === 'draft' ? __('ai.behavior_sandbox_doctrine_draft') : __('ai.behavior_sandbox_doctrine_none') }}</li>
                        <li>{{ __('ai.behavior_sandbox_capability_line', ['capability' => isset($sr['capability']) ? $capabilityLabel((string) $sr['capability']) : '—']) }}</li>
                        <li>{{ __('ai.behavior_sandbox_scope', ['scope' => __('ai.behavior_sandbox_scope_organization')]) }}</li>
                        <li data-behavior-sandbox-sources="{{ (int) ($sr['sources_count'] ?? 0) }}">{{ trans_choice('ai.behavior_sandbox_sources', (int) ($sr['sources_count'] ?? 0), ['count' => (int) ($sr['sources_count'] ?? 0)]) }}</li>
                        @php $ledgerEntries = (int) ($sr['ledger_entries'] ?? 0); @endphp
                        <li class="{{ $ledgerEntries > 0 ? 'text-amber-700 dark:text-amber-300' : '' }}" data-behavior-sandbox-ledgered="{{ $ledgerEntries > 0 ? '1' : '0' }}" data-behavior-sandbox-ledger-entries="{{ $ledgerEntries }}">{{ $ledgerEntries > 0 ? trans_choice('ai.behavior_sandbox_ledgered', $ledgerEntries, ['count' => $ledgerEntries]) : __('ai.behavior_sandbox_not_ledgered') }}</li>
                    </ul>

                    @if($srStatus === 'answered')
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.behavior_sandbox_answer') }}</p>
                        <div class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900/40 rounded-lg p-3 border border-gray-100 dark:border-gray-700" data-behavior-sandbox-answer>{{ $sr['answer'] ?? '' }}</div>
                    @elseif($srStatus === 'refused')
                        <p class="text-sm text-amber-700 dark:text-amber-300" data-behavior-sandbox-refusal="{{ $sr['refusal_reason'] ?? '' }}">{{ __('ai.behavior_sandbox_refused.'.($sr['refusal_reason'] ?? 'temporarily_unavailable')) }}</p>
                    @elseif($srStatus === 'no_sources')
                        @php $denied = is_array($sr['sources_denied'] ?? null) ? $sr['sources_denied'] : []; @endphp
                        <p class="text-sm text-gray-700 dark:text-gray-300" data-behavior-sandbox-no-sources>{{ __('ai.behavior_sandbox_no_sources') }}</p>
                        @if($denied !== [])
                            {{-- La raison technique du refus de source, traduite quand elle est connue. --}}
                            <ul class="mt-1 text-xs text-gray-500 dark:text-gray-400 list-disc list-inside" data-behavior-sandbox-denied>
                                @foreach($denied as $sourceName => $reason)
                                    <li>{{ \Illuminate\Support\Facades\Lang::has('ai.behavior_sandbox_source_denied.'.$reason) ? __('ai.behavior_sandbox_source_denied.'.$reason) : __('ai.behavior_sandbox_source_denied.other') }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @elseif($srStatus === 'failed')
                        <p class="text-sm text-red-700 dark:text-red-300" data-behavior-sandbox-failed>{{ __('ai.behavior_sandbox_failed') }}</p>
                    @endif
                </div>
            @endif
        </section>

        {{-- 4. COUVERTURE DU SYSTEME NERVEUX --}}
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-behavior-coverage data-behavior-coverage-covered="{{ $coveredCount }}" data-behavior-coverage-total="{{ $totalCount }}">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('ai.behavior_coverage_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('ai.behavior_coverage_help') }}</p>
            <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                @foreach($coveredCapabilities as $capability)
                    <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-3 py-2" data-behavior-coverage-item="{{ $capability->id }}" data-behavior-coverage-kind="covered">
                        <span class="text-gray-900 dark:text-gray-100">{{ $capabilityLabel($capability->id) }}</span>
                        <span class="shrink-0 text-xs text-emerald-600 dark:text-emerald-400">✓ {{ __('ai.behavior_coverage_covered') }}</span>
                    </li>
                @endforeach
                @foreach($inheritedFunctions as $functionId)
                    <li class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-3 py-2" data-behavior-coverage-item="{{ $functionId }}" data-behavior-coverage-kind="inherited">
                        <span class="text-gray-700 dark:text-gray-300">{{ __('ai.inherited_label.'.$functionId) }}</span>
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">— {{ __('ai.behavior_coverage_inherited') }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-sm font-medium text-gray-900 dark:text-gray-100" data-behavior-coverage-summary>
                {{ trans_choice('ai.behavior_coverage_summary', $coveredCount, ['covered' => $coveredCount, 'total' => $totalCount]) }}
            </p>
        </section>
    </div>

    <script>
        // Le bac a sable teste les brouillons tels qu'ils sont dans les champs
        // au moment de l'envoi (non enregistres).
        //
        // TASK-1348 : la Constitution de l'Organization part avec la doctrine.
        // Chaque champ est traite independamment — un formulaire sans l'un des
        // deux (Card retiree, futur remaniement) continue d'envoyer l'autre.
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('[data-behavior-sandbox-form]');
            if (!form) { return; }

            var pairs = [
                [document.getElementById('doctrine-body'), document.querySelector('[data-behavior-sandbox-body]')],
                [document.querySelector('[data-behavior-org-constitution-input]'), document.querySelector('[data-behavior-sandbox-constitution-body]')],
            ];

            form.addEventListener('submit', function () {
                pairs.forEach(function (pair) {
                    if (pair[0] && pair[1]) { pair[1].value = pair[0].value; }
                });
            });
        });
    </script>
</x-org-admin-layout>
