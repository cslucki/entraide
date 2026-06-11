<x-admin-layout title="Historique IA">
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Historique des interactions IA
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Consultez les appels IA exécutés via le lab de supervision.
                </p>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ $interactions->total() }} interaction(s)
            </div>
        </div>

        {{-- Filters --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <form method="GET" action="{{ route('admin.ai-interactions') }}" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Provider</label>
                    <select name="provider" class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous</option>
                        @foreach($providers as $p)
                            <option value="{{ $p }}" {{ ($filters['provider'] ?? '') === $p ? 'selected' : '' }}>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Scénario</label>
                    <select name="scenario_id" class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous</option>
                        @foreach($scenarios as $s)
                            <option value="{{ $s }}" {{ ($filters['scenario_id'] ?? '') === $s ? 'selected' : '' }}>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Statut</label>
                    <select name="status" class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous</option>
                        <option value="success" {{ ($filters['status'] ?? '') === 'success' ? 'selected' : '' }}>Success</option>
                        <option value="error" {{ ($filters['status'] ?? '') === 'error' ? 'selected' : '' }}>Error</option>
                    </select>
                </div>

                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Du</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Au</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex-[2] min-w-[200px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Recherche</label>
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Excerpt ou résumé..."
                           class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                        Filtrer
                    </button>
                    <a href="{{ route('admin.ai-interactions') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                        Réinit.
                    </a>
                </div>
            </form>
        </div>

        {{-- Table --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($interactions->count() === 0)
                <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                    <p class="text-sm">Aucune interaction IA trouvée.</p>
                    <p class="text-xs mt-1">Exécutez un scénario dans <a href="{{ route('admin.ai-supervision') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Supervision IA</a> pour générer des entrées.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Scénario</th>
                                <th class="px-4 py-3">Provider</th>
                                <th class="px-4 py-3">Modèle</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Excerpt</th>
                                <th class="px-4 py-3 text-right">Latence</th>
                                <th class="px-4 py-3 text-right">Coût</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($interactions as $interaction)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('admin.ai-interactions.show', $interaction) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ $interaction->created_at->format('d/m/Y H:i') }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        {{ $interaction->scenario_id }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ $interaction->provider ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $interaction->model ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if($interaction->status === 'success')
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300">success</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300">{{ $interaction->status }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 max-w-xs truncate" title="{{ $interaction->input_excerpt }}">
                                    {{ Str::limit($interaction->input_excerpt, 60) }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    {{ $interaction->latency_ms ? $interaction->latency_ms . ' ms' : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    {{ $interaction->cost_usd ? '$' . number_format($interaction->cost_usd, 6) : '—' }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                    {{ $interactions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
