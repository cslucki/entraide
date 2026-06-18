<x-admin-layout title="Utilisation IA">
    <div class="max-w-7xl mx-auto space-y-6">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Utilisation IA
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Appels IA des fonctionnalités utilisateur (blog) et supervision.
                </p>
            </div>
            <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                <span>{{ $blogInteractions->total() }} blog</span>
                <span class="text-gray-300 dark:text-gray-600">|</span>
                <span>{{ $adminInteractions->total() }} supervision</span>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <form method="GET" action="{{ route('admin.ia-usage') }}" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Source</label>
                    <select name="source" class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Toutes</option>
                        <option value="blog" {{ ($filters['source'] ?? '') === 'blog' ? 'selected' : '' }}>Blog IA</option>
                        <option value="admin" {{ ($filters['source'] ?? '') === 'admin' ? 'selected' : '' }}>Supervision</option>
                    </select>
                </div>

                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Fonctionnalité</label>
                    <select name="feature" class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Toutes</option>
                        @foreach($features as $f)
                            <option value="{{ $f }}" {{ ($filters['feature'] ?? '') === $f ? 'selected' : '' }}>{{ $f }}</option>
                        @endforeach
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
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Texte du prompt ou réponse..."
                           class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                        Filtrer
                    </button>
                    <a href="{{ route('admin.ia-usage') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                        Réinit.
                    </a>
                </div>
            </form>
        </div>

        {{-- Blog interactions --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Blog IA ({{ $blogInteractions->total() }})</h3>
            </div>

            @if($blogInteractions->count() === 0)
                <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                    <p class="text-sm">Aucune interaction blog IA.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Utilisateur</th>
                                <th class="px-4 py-3">Organisation</th>
                                <th class="px-4 py-3">Fonctionnalité</th>
                                <th class="px-4 py-3">Modèle</th>
                                <th class="px-4 py-3 max-w-xs">Prompt</th>
                                <th class="px-4 py-3 text-right">Tokens</th>
                                <th class="px-4 py-3 text-right">Coût</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($blogInteractions as $interaction)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('admin.ia-usage.show', $interaction) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ $interaction->created_at->format('d/m/Y H:i') }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $interaction->user?->name ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $interaction->organization?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300">
                                        {{ $interaction->feature }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ $interaction->model ?? '—' }}</td>
                                <td class="px-4 py-3 max-w-xs truncate" title="{{ $interaction->prompt }}">
                                    {{ Str::limit($interaction->prompt, 60) }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    {{ $interaction->input_tokens + $interaction->output_tokens }}
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
                    {{ $blogInteractions->links() }}
                </div>
            @endif
        </div>

        {{-- Admin supervision interactions --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Supervision IA ({{ $adminInteractions->total() }})</h3>
            </div>

            @if($adminInteractions->count() === 0)
                <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                    <p class="text-sm">Aucune interaction supervision IA.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Utilisateur</th>
                                <th class="px-4 py-3">Scénario</th>
                                <th class="px-4 py-3">Provider</th>
                                <th class="px-4 py-3">Modèle</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3 max-w-xs">Excerpt</th>
                                <th class="px-4 py-3 text-right">Latence</th>
                                <th class="px-4 py-3 text-right">Coût</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($adminInteractions as $interaction)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('admin.ia-usage.show-admin', $interaction) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ $interaction->created_at->format('d/m/Y H:i') }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $interaction->user?->name ?? '—' }}</td>
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
                    {{ $adminInteractions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
