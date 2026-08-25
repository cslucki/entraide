{{--
    « Economie IA BouclePro » — cockpit plateforme (TASK-1223, TASK-1228, TASK-1270, TASK-1306).

    Le SuperAdmin voit des comptes, des sommes de couts CONNUS et des jalons
    temporels par Organization. Jamais un contenu tenant (message, prompt,
    reponse, document, chunk, question posee), jamais une cle.
    « — » = non mesure, 0 = vrai zero, inconnu != gratuit.

    AUTORITE (TASK-1228) : les chiffres economiques viennent de
    `OrganizationAiEconomicUsage::perOrganization()` — la MEME autorite 1222
    que le releve de chaque Organization ; la ligne d'une Organization ICI est
    son releve, le total est la somme des lignes + « sans Organization ».

    TASK-1306 : ce cockpit est desormais le point d'entree central de
    configuration IA du SuperAdmin. Le tableau principal ne porte QUE les
    colonnes essentielles (visibles sans scroll horizontal) ; les metriques
    detaillees vivent dans une ligne « Détails » repliable, sans rien perdre.
    Le formulaire « Configurer » (ouvert en modale, hors du <table>) est
    EXACTEMENT celui de /org/{organization}/admin/ai — organization.admin.ai.update,
    OrgAdminController::updateAi() — inclus via le meme partiel Blade,
    aucune deuxieme autorite de credential, aucune cle jamais reaffichee.
--}}
@php
    $cost = static function ($value): string {
        return $value === null ? '—' : '$'.number_format((float) $value, 6);
    };
    $unattributedCount = $unattributed['total_count'] ?? 0;
@endphp

<x-admin-layout>
    <x-slot name="title">{{ __('ai.platform_title') }}</x-slot>

    <div class="space-y-6" x-data="{ openConfigure: null, openDetails: {} }">
        <div>
            <h1 class="text-2xl font-bold dark:text-white">{{ __('ai.platform_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.platform_intro') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" data-platform-period>{{ __('ai.economy_period_label', ['from' => $from->format('d/m/Y'), 'to' => $to->subSecond()->format('d/m/Y')]) }}</p>
        </div>

        {{-- Cards globales : la meme autorite que chaque Organization. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-platform-cards>
            @foreach([
                ['label' => __('ai.platform_card_provider_cost'), 'value' => $cost($totals['known_cost_usd']), 'key' => 'known-cost', 'attr' => $totals['known_cost_usd']],
                ['label' => __('ai.platform_card_active_organizations'), 'value' => number_format($totals['active_organizations']).' '.__('ai.platform_of').' '.number_format($totals['organizations']), 'key' => 'active-organizations', 'attr' => $totals['active_organizations']],
                ['label' => __('ai.platform_card_ai_users'), 'value' => number_format($totals['ai_users']), 'key' => 'ai-users', 'attr' => $totals['ai_users']],
                ['label' => __('ai.platform_card_generation'), 'value' => number_format($totals['generation']), 'key' => 'generation', 'attr' => $totals['generation'], 'sub' => $totals['generation_sandbox'] > 0 ? __('ai.economy_nature_sandbox').' : '.number_format($totals['generation_sandbox']) : null],
                ['label' => __('ai.platform_card_search'), 'value' => number_format($totals['embedding_query']), 'key' => 'search', 'attr' => $totals['embedding_query']],
                ['label' => __('ai.platform_card_ingestion'), 'value' => number_format($totals['embedding_ingestion']), 'key' => 'ingestion', 'attr' => $totals['embedding_ingestion'], 'sub' => $totals['embedding_undeclared'] > 0 ? __('ai.economy_undeclared_suffix', ['count' => number_format($totals['embedding_undeclared'])]) : null],
                ['label' => __('ai.platform_card_unknown_cost'), 'value' => number_format($totals['unknown_count']), 'key' => 'unknown', 'attr' => $totals['unknown_count'], 'warn' => $totals['unknown_count'] > 0, 'sub' => $totals['unevaluated_count'] > 0 ? trans_choice('ai.economy_unevaluated_count', $totals['unevaluated_count'], ['count' => $totals['unevaluated_count']]) : null],
                ['label' => __('ai.platform_card_failed'), 'value' => number_format($totals['failed']), 'key' => 'failed', 'attr' => $totals['failed']],
            ] as $card)
                <div class="bg-white dark:bg-gray-800 rounded-xl border p-4 {{ ($card['warn'] ?? false) ? 'border-amber-300 dark:border-amber-700/60' : 'border-gray-200 dark:border-gray-700' }}" data-platform-card="{{ $card['key'] }}" data-platform-card-value="{{ $card['attr'] ?? '' }}">
                    <div class="text-xs uppercase {{ ($card['warn'] ?? false) ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">{{ $card['label'] }}</div>
                    <div class="text-xl font-semibold mt-1 font-mono {{ ($card['warn'] ?? false) ? 'text-amber-800 dark:text-amber-200' : 'text-gray-900 dark:text-gray-100' }}">{{ $card['value'] }}</div>
                    @if(! empty($card['sub']))
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $card['sub'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('ai.platform_card_configured') }} : {{ number_format($totals['configured']) }} ·
            {{ __('ai.platform_card_declared_budget') }} : {{ $totals['declared_budget_usd'] !== null ? '$'.number_format($totals['declared_budget_usd'], 2).' ('.number_format($totals['declared_budget_count']).')' : '—' }}
        </p>

        {{--
            Table par Organization — TASK-1306 : uniquement les colonnes
            essentielles, visibles sans scroll horizontal. Aucun <form>, aucun
            <input> ici (invariant TASK-1270) : les actions ouvrent une modale
            rendue APRES le tableau (meme composant Alpine, scope partagé).
        --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_organization') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_ready') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_credential') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_management') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_budget') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_consumed') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('navigation.org_admin_table_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($organizations as $organization)
                            @php
                                $setting = $settings[(string) $organization->id] ?? null;
                                $eco = $economics[(string) $organization->id] ?? null;
                                $meta = $ledger[(string) $organization->id] ?? null;
                                $index = $rag[(string) $organization->id] ?? null;
                                $budget = $setting !== null && $setting['monthly_budget_usd'] !== null ? (float) $setting['monthly_budget_usd'] : null;
                                $consumed = $eco['total_known_cost_usd'] ?? null;
                                $remaining = $budget !== null ? $budget - (float) ($consumed ?? 0.0) : null;
                                $unknownCount = $eco['total_unknown_count'] ?? 0;
                                $hasCredential = $setting['has_credential'] ?? false;
                                $credentialMode = $setting['credential_management_mode'] ?? \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM;
                                $credentialUpdatedAt = $setting['api_key_updated_at'] ?? null;
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition" data-platform-org="{{ $organization->id }}" data-platform-org-known-cost="{{ $consumed ?? '' }}" data-platform-org-unknown="{{ $unknownCount }}">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                    <a href="{{ route('organization.admin.dashboard', ['organization' => $organization->slug]) }}" class="hover:underline hover:text-indigo-600 dark:hover:text-indigo-400" data-platform-org-link="{{ $organization->slug }}">{{ $organization->name }}</a>
                                    <div class="font-mono text-[10px] text-gray-400">{{ $setting !== null ? $setting['provider'].' / '.$setting['model'] : '—' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($setting !== null && $setting['ready'])
                                        <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('ai.platform_yes') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">{{ __('ai.platform_no') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs {{ $hasCredential ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}" data-platform-org-credential="{{ $hasCredential ? 'configured' : 'not-configured' }}">
                                        {{ $hasCredential ? __('ai.platform_credential_configured') : __('ai.platform_credential_not_configured') }}
                                    </span>
                                    @if($hasCredential && $credentialUpdatedAt)
                                        <div class="text-[10px] text-gray-400">{{ __('ai.platform_credential_updated_at', ['date' => $credentialUpdatedAt->format('d/m/Y')]) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $credentialMode === \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}" data-platform-org-credential-mode="{{ $credentialMode }}">
                                        {{ $credentialMode === \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION ? __('admin.organization_ai_credential_mode_organization') : __('admin.organization_ai_credential_mode_platform') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-600 dark:text-gray-400">{{ $budget !== null ? '$'.number_format($budget, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($consumed) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="button" @click="openConfigure = '{{ $organization->id }}'" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-medium" title="{{ __('ai.platform_configure_title') }}" data-platform-org-configure="{{ $organization->slug }}">{{ __('ai.platform_configure') }}</button>
                                        <a href="{{ route('organization.admin.ai-cockpit', ['organization' => $organization->slug]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-medium" data-platform-org-cockpit-link="{{ $organization->slug }}">{{ __('ai.platform_link_cockpit') }}</a>
                                        <button type="button" @click="openDetails['{{ $organization->id }}'] = ! openDetails['{{ $organization->id }}']" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xs font-medium" data-platform-org-details-toggle="{{ $organization->slug }}">
                                        <span x-text="openDetails['{{ $organization->id }}'] ? '▴ {{ __('ai.platform_details_toggle') }}' : '▾ {{ __('ai.platform_details_toggle') }}'">▾ {{ __('ai.platform_details_toggle') }}</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr x-show="openDetails['{{ $organization->id }}']" x-cloak class="bg-gray-50 dark:bg-gray-900/30" data-platform-org-details="{{ $organization->slug }}">
                                <td colspan="7" class="px-4 py-4">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4 text-xs mb-4">
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_users') }}</div>
                                            <div class="font-mono text-gray-900 dark:text-gray-100">{{ number_format($eco['ai_users_count'] ?? 0) }}</div>
                                        </div>
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_generation') }}</div>
                                            <div class="font-mono text-gray-900 dark:text-gray-100">
                                                {{ number_format($eco['generation']['trace_count'] ?? 0) }}
                                                @if(($eco['generation_sandbox']['trace_count'] ?? 0) > 0)
                                                    <span class="text-gray-400" title="{{ __('ai.economy_nature_sandbox') }}">({{ number_format($eco['generation_sandbox']['trace_count']) }})</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_rag') }}</div>
                                            <div class="font-mono text-gray-900 dark:text-gray-100">
                                                {{ number_format($eco['embedding_query']['invocation_count'] ?? 0) }} / {{ number_format($eco['embedding_ingestion']['invocation_count'] ?? 0) }}
                                                @if(($eco['embedding_undeclared']['invocation_count'] ?? 0) > 0)
                                                    <span class="text-gray-400" title="{{ __('ai.economy_nature_embedding_undeclared') }}">{{ __('ai.economy_undeclared_suffix', ['count' => number_format($eco['embedding_undeclared']['invocation_count'])]) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_unknown') }}</div>
                                            <div class="font-mono {{ $unknownCount > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($unknownCount) }}</div>
                                        </div>
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_failed') }}</div>
                                            <div class="font-mono {{ ($meta['failed_count'] ?? 0) > 0 ? 'text-red-500' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($meta['failed_count'] ?? 0) }}</div>
                                        </div>
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_chunks') }}</div>
                                            <div class="font-mono text-gray-900 dark:text-gray-100">{{ $index !== null ? number_format($index['chunks']) : '0' }}</div>
                                        </div>
                                        <div>
                                            <div class="text-gray-400 uppercase">{{ __('ai.platform_col_last_activity') }}</div>
                                            <div class="text-gray-500 dark:text-gray-400">{{ $meta !== null && $meta['last_activity_at'] !== null ? $meta['last_activity_at']->format('d/m H:i') : '—' }}</div>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs border-t border-gray-200 dark:border-gray-700 pt-3">
                                        <a href="{{ route('organization.admin.ai-behavior', ['organization' => $organization->slug]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">{{ __('ai.platform_link_behavior') }}</a>
                                        <a href="{{ route('organization.admin.ai-knowledge', ['organization' => $organization->slug]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">{{ __('ai.platform_link_knowledge') }}</a>
                                        <a href="{{ route('organization.admin.ai-consumption', ['organization' => $organization->slug]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">{{ __('ai.platform_link_consumption') }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-400">{{ __('ai.platform_empty') }}</td>
                            </tr>
                        @endforelse
                        @if($deletedCount > 0)
                            {{-- Organizations supprimees : hors de la liste, dans le total. --}}
                            <tr class="bg-gray-50 dark:bg-gray-900/30" data-platform-deleted="{{ $deletedCount }}">
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 italic" colspan="4">{{ trans_choice('ai.platform_row_deleted', $deletedCount, ['count' => $deletedCount]) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($deleted['total_known_cost_usd']) }}</td>
                                <td class="px-4 py-3 text-gray-400">—</td>
                            </tr>
                        @endif
                        @if($unattributedCount > 0)
                            {{-- Traces sans Organization : comptees, jamais reparties. --}}
                            <tr class="bg-gray-50 dark:bg-gray-900/30" data-platform-unattributed="{{ $unattributedCount }}">
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 italic" colspan="4">{{ __('ai.platform_row_unattributed') }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($unattributed['total_known_cost_usd']) }}</td>
                                <td class="px-4 py-3 text-gray-400">—</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.economy_authority_note') }}</p>

        {{--
            TASK-1306 : modales « Configurer », rendues HORS du <table>
            ci-dessus (invariant TASK-1270 : le tableau lui-meme ne porte
            jamais de <form> ni de <input>) mais DANS le meme scope Alpine
            (openConfigure) que les boutons qui les ouvrent. Chaque modale
            inclut EXACTEMENT le meme partiel que /org/{organization}/admin/ai
            — meme route, meme controleur, meme validation, meme regle
            « cle vide = conservee » : aucune deuxieme autorite de credential.
        --}}
        @foreach($organizations as $organization)
            @php
                $orgSetting = $aiSettingModels->get($organization->id);
                $orgHasKey = $orgSetting?->hasCredential() ?? false;
                $orgCredentialMode = \App\Models\OrganizationAiSetting::effectiveCredentialMode($orgSetting);
            @endphp
            <div x-show="openConfigure === '{{ $organization->id }}'" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 data-platform-org-modal="{{ $organization->slug }}">
                <div class="absolute inset-0 bg-black/50" @click="openConfigure = null"></div>
                <div class="relative bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-xl max-w-xl w-full max-h-[90vh] overflow-y-auto p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.platform_modal_title', ['name' => $organization->name]) }}</h2>
                        <button type="button" @click="openConfigure = null" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" data-platform-org-modal-close="{{ $organization->slug }}">✕</button>
                    </div>

                    <div class="mb-6 pb-6 border-b border-gray-100 dark:border-gray-700">
                        @include('admin.org._ai-credential-mode-form', ['organization' => $organization, 'credentialMode' => $orgCredentialMode])
                    </div>

                    @include('admin.org._ai-credential-form', [
                        'organization' => $organization,
                        'setting' => $orgSetting,
                        'providers' => $providers,
                        'defaultModel' => $defaultModel,
                        'hasKey' => $orgHasKey,
                    ])

                    <button type="button" @click="openConfigure = null" class="mt-3 w-full px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition" data-platform-org-modal-cancel="{{ $organization->slug }}">
                        {{ __('ai.platform_modal_close') }}
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</x-admin-layout>
