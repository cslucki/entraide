<x-admin-layout title="Benchmark IA">
    <div class="max-w-6xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Benchmark IA — Coûts & performances
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Dashboard analytics read-only des coûts et performances des appels IA.
                </p>
            </div>
        </div>

        @if($totalInteractions === 0)
            {{-- Empty state --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8 text-center">
                <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/>
                </svg>
                <p class="text-gray-500 dark:text-gray-400 mb-4">
                    Aucune interaction IA enregistrée
                </p>
                <a href="{{ route('admin.ai-supervision') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    Accéder à la supervision IA
                </a>
            </div>
        @else
            {{-- Section 1: Stat cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Coût total</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($totalCost, 4, ',', ' ') }} €</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Interactions</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $totalInteractions }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Latence moyenne</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $avgLatency ? number_format($avgLatency, 0) : '—' }} ms</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Tokens moyens</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $avgTokens ? number_format($avgTokens, 0) : '—' }}</p>
                </div>
            </div>

            {{-- Section 2: Coût par provider --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Coût par provider</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-900/40 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-5 py-3">Provider</th>
                                <th class="px-5 py-3">Appels</th>
                                <th class="px-5 py-3">Coût total</th>
                                <th class="px-5 py-3">Tokens</th>
                                <th class="px-5 py-3">Latence moyenne</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($byProvider as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="px-5 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $row->provider }}</td>
                                    <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $row->calls }}</td>
                                    <td class="px-5 py-3 text-gray-900 dark:text-gray-100">{{ number_format($row->total_cost, 4, ',', ' ') }} €</td>
                                    <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ number_format($row->total_tokens) }}</td>
                                    <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $row->avg_latency }} ms</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-6 text-center text-gray-500 dark:text-gray-400">
                                        Aucune donnée par provider.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Section 3: Coût par scénario --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Coût par scénario</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-900/40 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-5 py-3">Scénario</th>
                                <th class="px-5 py-3">Appels</th>
                                <th class="px-5 py-3">Coût total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($byScenario as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="px-5 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $row->scenario_id }}</td>
                                    <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $row->calls }}</td>
                                    <td class="px-5 py-3 text-gray-900 dark:text-gray-100">{{ number_format($row->total_cost, 4, ',', ' ') }} €</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-6 text-center text-gray-500 dark:text-gray-400">
                                        Aucune donnée par scénario.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Section 4: Dernières interactions --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Dernières interactions</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-900/40 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-5 py-3">Scénario</th>
                                <th class="px-5 py-3">Provider</th>
                                <th class="px-5 py-3">Statut</th>
                                <th class="px-5 py-3">Coût</th>
                                <th class="px-5 py-3">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($lastInteractions as $interaction)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('admin.ai-interactions.show', $interaction) }}"
                                           class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
                                            {{ $interaction->scenario_id }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ $interaction->provider }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            {{ $interaction->status === 'success' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : '' }}
                                            {{ $interaction->status === 'error' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : '' }}
                                            {{ $interaction->status === 'pending' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300' : '' }}">
                                            {{ $interaction->status }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-900 dark:text-gray-100">{{ number_format($interaction->cost_usd, 4, ',', ' ') }} €</td>
                                    <td class="px-5 py-3 text-gray-500 dark:text-gray-400 text-xs">{{ $interaction->created_at->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-6 text-center text-gray-500 dark:text-gray-400">
                                        Aucune interaction récente.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-admin-layout>
