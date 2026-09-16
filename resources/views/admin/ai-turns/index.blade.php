<x-admin-layout title="Inspector IA">
    <div class="max-w-5xl mx-auto space-y-6">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Inspector IA — la trace du tour</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Lecture seule : ce que le tour a écrit de lui-même (bloc <code>turn</code>, schéma 1). Rien n'est rejoué, rien n'est deviné.
                </p>
            </div>
        </div>

        {{-- Lookup par identifiant : GET, sans effet. --}}
        <form method="get" action="{{ route('admin.ai-turns') }}" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 flex flex-col sm:flex-row gap-3 sm:items-end">
            <label class="flex-1 text-sm">
                <span class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Identifiant d'un tour</span>
                <input type="text" name="interaction" value="{{ request('interaction') }}" placeholder="uuid d'une ai_interaction ou d'une ligne assistant du Shell"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono" data-inspector-lookup>
            </label>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">Lire ce tour</button>
        </form>

        @if (session('inspector_error'))
            <p class="text-sm text-red-600 dark:text-red-400" data-inspector-error>{{ session('inspector_error') }}</p>
        @endif

        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Derniers tours tracés ({{ $recents->count() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-left">Quand</th>
                            <th class="px-4 py-2 text-left">Organization</th>
                            <th class="px-4 py-2 text-left">Chemin</th>
                            <th class="px-4 py-2 text-left">Verdict</th>
                            <th class="px-4 py-2 text-left">Code</th>
                            <th class="px-4 py-2 text-left"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700" data-inspector-recents>
                        @forelse ($recents as $ligne)
                            @php $turn = is_array($ligne->metadata['turn'] ?? null) ? $ligne->metadata['turn'] : []; @endphp
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $ligne->created_at?->format('d/m H:i:s') }}</td>
                                <td class="px-4 py-2 text-gray-900 dark:text-gray-100">{{ $ligne->organization?->slug ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $turn['identity']['execution_path'] ?? 'UNAVAILABLE' }}</td>
                                <td class="px-4 py-2">{{ $turn['status'] ?? 'UNAVAILABLE' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $turn['reason_code'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('admin.ai-turns.show', $ligne) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Lire</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">Aucun tour tracé pour l'instant.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
