{{--
    Hub « IA & connaissances » (TASK-1223) — l'etat du systeme IA de
    l'Organization en une page, read-only, avec des liens vers les consoles
    existantes (config 1212, connaissances 1217, consommation 1219).

    Regles d'affichage : « — » = inconnu ; 0 = vrai zero ; la cle d'acces
    n'apparait JAMAIS (seulement definie / non definie).
--}}
@php
    $cost = static function ($value): string {
        return $value === null ? '—' : '$'.number_format((float) $value, 6);
    };
@endphp

<x-org-admin-layout :title="__('ai.cockpit_title')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.cockpit_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.cockpit_intro') }}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- 1. CONFIGURATION --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-cockpit-config>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.cockpit_config_title') }}</h2>
                @if($ready)
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300" data-cockpit-ready>{{ __('ai.cockpit_config_ready') }}</span>
                @else
                    <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300" data-cockpit-ready>{{ __('ai.cockpit_config_not_ready') }}</span>
                @endif
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_config_provider') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100 font-mono text-xs">{{ $setting?->provider ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_config_model') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100 font-mono text-xs">{{ $setting?->model ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_config_credential') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100">
                        {{-- Jamais la valeur : seulement definie / non definie. --}}
                        {{ filled($setting?->api_key) ? __('ai.cockpit_config_credential_set') : __('ai.cockpit_config_credential_missing') }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_config_budget') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100 font-mono text-xs" data-cockpit-budget>{{ $monthlyBudgetUsd !== null ? '$'.number_format((float) $monthlyBudgetUsd, 2) : '—' }}</dd>
                </div>
            </dl>
            <a href="{{ route('organization.admin.ai', ['organization' => $organization->slug]) }}"
               class="inline-block mt-4 text-sm text-sky-600 dark:text-sky-400 hover:underline">
                {{ __('ai.cockpit_config_manage') }} →
            </a>
        </div>

        {{-- 2. COMPORTEMENT --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-cockpit-behavior>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.cockpit_behavior_title') }}</h2>
                <span class="text-xs text-gray-400">{{ __('ai.cockpit_behavior_constitution') }} {{ $constitutionVersion }}</span>
            </div>
            <ul class="space-y-3 text-sm">
                @foreach($capabilities as $capability)
                    <li class="flex items-start justify-between gap-3" data-cockpit-capability="{{ $capability['id'] }}">
                        <div>
                            <div class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ $capability['id'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                @if($capability['human_validation'])
                                    {{ __('ai.cockpit_behavior_human_validation') }}
                                @elseif($capability['read_only'])
                                    {{ __('ai.cockpit_behavior_read_only') }}
                                @endif
                            </div>
                        </div>
                        @if($capability['prompt_active'])
                            <span class="shrink-0 text-xs text-emerald-600 dark:text-emerald-400">{{ __('ai.cockpit_behavior_prompt_active') }}</span>
                        @else
                            <span class="shrink-0 text-xs text-amber-600 dark:text-amber-400">{{ __('ai.cockpit_behavior_prompt_missing') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            {{-- TASK-1227 : la doctrine de l'Organization, et le lien vers la page Comportement. --}}
            <p class="text-xs mt-4 {{ $doctrine ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500 dark:text-gray-400' }}" data-cockpit-doctrine="{{ $doctrine ? 'v'.$doctrine->version : 'none' }}">
                {{ $doctrine ? __('ai.cockpit_behavior_doctrine_active', ['version' => $doctrine->version]) : __('ai.cockpit_behavior_doctrine_none') }}
            </p>
            <a href="{{ route('organization.admin.ai-behavior', ['organization' => $organization->slug]) }}"
               class="inline-block mt-2 text-sm text-sky-600 dark:text-sky-400 hover:underline" data-cockpit-behavior-open>
                {{ __('ai.cockpit_behavior_open') }} →
            </a>
        </div>

        {{-- 3. CONNAISSANCES --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-cockpit-knowledge>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ __('ai.cockpit_knowledge_title') }}</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_knowledge_dossiers') }}</dt><dd class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($rag['dossiers']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_knowledge_articles') }}</dt><dd class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($rag['articles']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_knowledge_files') }}</dt><dd class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($rag['files']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_knowledge_chunks') }}</dt><dd class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($rag['chunks']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_knowledge_indexed_sources') }}</dt><dd class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($rag['indexed_sources']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_knowledge_last_indexed') }}</dt><dd class="font-mono text-xs text-gray-900 dark:text-gray-100">{{ $rag['last_indexed_at'] ? \Carbon\Carbon::parse($rag['last_indexed_at'])->format('d/m/Y H:i') : '—' }}</dd></div>
            </dl>
            <a href="{{ route('organization.admin.ai-knowledge', ['organization' => $organization->slug]) }}"
               class="inline-block mt-4 text-sm text-sky-600 dark:text-sky-400 hover:underline">
                {{ __('ai.cockpit_knowledge_open_console') }} →
            </a>
        </div>

        {{-- 4. CONSOMMATION (autorite economique 1222) --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-cockpit-consumption>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ __('ai.cockpit_consumption_title') }}</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_consumption_generation') }}</dt>
                    <dd class="font-mono text-xs text-gray-900 dark:text-gray-100">
                        {{ number_format($economics['generation']['trace_count']) }} {{ __('ai.cockpit_calls') }} · {{ $cost($economics['generation']['known_cost_usd']) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_consumption_ingestion') }}</dt>
                    <dd class="font-mono text-xs text-gray-900 dark:text-gray-100">
                        {{ number_format($economics['embedding_ingestion']['invocation_count']) }} {{ __('ai.cockpit_calls') }} · {{ $cost($economics['embedding_ingestion']['known_cost_usd']) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.cockpit_consumption_query') }}</dt>
                    <dd class="font-mono text-xs text-gray-900 dark:text-gray-100">
                        {{ number_format($economics['embedding_query']['invocation_count']) }} {{ __('ai.cockpit_calls') }} · {{ $cost($economics['embedding_query']['known_cost_usd']) }}
                    </dd>
                </div>
                <div class="flex justify-between border-t border-gray-100 dark:border-gray-700 pt-2 mt-2">
                    <dt class="font-medium text-gray-900 dark:text-gray-100">{{ __('ai.cockpit_consumption_known_total') }}</dt>
                    <dd class="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100" data-cockpit-known-total>{{ $cost($economics['total_known_cost_usd']) }}</dd>
                </div>
            </dl>
            @if($economics['total_unknown_count'] > 0 || $economics['total_unevaluated_count'] > 0)
                <p class="text-xs text-amber-600 dark:text-amber-400 mt-3" data-cockpit-unknown>
                    @if($economics['total_unknown_count'] > 0){{ $economics['total_unknown_count'] }} {{ trans_choice('ai.cockpit_consumption_unknown', $economics['total_unknown_count']) }}@endif
                    @if($economics['total_unknown_count'] > 0 && $economics['total_unevaluated_count'] > 0) · @endif
                    @if($economics['total_unevaluated_count'] > 0){{ $economics['total_unevaluated_count'] }} {{ trans_choice('ai.cockpit_consumption_unevaluated', $economics['total_unevaluated_count']) }}@endif
                </p>
            @endif
            <a href="{{ route('organization.admin.ai-consumption', ['organization' => $organization->slug]) }}"
               class="inline-block mt-4 text-sm text-sky-600 dark:text-sky-400 hover:underline">
                {{ __('ai.cockpit_consumption_open_console') }} →
            </a>
        </div>
    </div>
</x-org-admin-layout>
