<x-admin-layout title="Traductions">
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 dark:border-green-900 dark:bg-green-900/20">
            <p class="text-xs font-semibold uppercase tracking-wide text-green-700 dark:text-green-400">OK</p>
            <p class="mt-1 text-2xl font-bold text-green-800 dark:text-green-200">{{ $stats['ok'] }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-900/20">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">FR manquante</p>
            <p class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ $stats['missing_fr'] }}</p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900 dark:bg-red-900/20">
            <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">EN manquante</p>
            <p class="mt-1 text-2xl font-bold text-red-800 dark:text-red-200">{{ $stats['missing_en'] }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <select name="group" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="_all" {{ $activeGroup === '_all' || !$activeGroup ? 'selected' : '' }}>Tous les groupes</option>
                @foreach($groups as $g)
                    <option value="{{ $g }}" {{ $activeGroup === $g ? 'selected' : '' }}>{{ $g }}</option>
                @endforeach
            </select>

            <select name="status" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="_all" {{ $activeStatus === '_all' || !$activeStatus ? 'selected' : '' }}>Tous les statuts</option>
                <option value="OK" {{ $activeStatus === 'OK' ? 'selected' : '' }}>OK</option>
                <option value="MISSING_FR" {{ $activeStatus === 'MISSING_FR' ? 'selected' : '' }}>FR manquante</option>
                <option value="MISSING_EN" {{ $activeStatus === 'MISSING_EN' ? 'selected' : '' }}>EN manquante</option>
                <option value="EMPTY_FR" {{ $activeStatus === 'EMPTY_FR' ? 'selected' : '' }}>FR vide</option>
                <option value="EMPTY_EN" {{ $activeStatus === 'EMPTY_EN' ? 'selected' : '' }}>EN vide</option>
                <option value="NESTED" {{ $activeStatus === 'NESTED' ? 'selected' : '' }}>Structure imbriquée</option>
            </select>

            <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Rechercher..."
                   class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:placeholder-gray-500">

            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition">Filtrer</button>

            @if($activeGroup || $activeStatus || $search)
                <a href="{{ route('admin.translations') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 transition">Réinitialiser</a>
            @endif
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Groupe</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Clé</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">FR</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">EN</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $entry['status'] !== 'OK' ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                        <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $entry['group'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $entry['key'] }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300 {{ is_array($entry['fr']) ? 'text-gray-400 italic' : ($entry['fr'] === null ? 'text-red-500 italic' : '') }}">
                            @if(is_array($entry['fr']))
                                <span class="text-xs text-gray-400 dark:text-gray-500">structure imbriquée</span>
                            @else
                                {{ $entry['fr'] ?? '—' }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300 {{ is_array($entry['en']) ? 'text-gray-400 italic' : ($entry['en'] === null ? 'text-red-500 italic' : '') }}">
                            @if(is_array($entry['en']))
                                <span class="text-xs text-gray-400 dark:text-gray-500">structure imbriquée</span>
                            @else
                                {{ $entry['en'] ?? '—' }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $statusColors = [
                                    'OK' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                    'MISSING_FR' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                    'MISSING_EN' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                    'EMPTY_FR' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                                    'EMPTY_EN' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                                    'NESTED' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                ];
                                $statusLabels = [
                                    'OK' => 'OK',
                                    'MISSING_FR' => 'FR manquante',
                                    'MISSING_EN' => 'EN manquante',
                                    'EMPTY_FR' => 'FR vide',
                                    'EMPTY_EN' => 'EN vide',
                                    'NESTED' => 'Structure imbriquée',
                                ];
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$entry['status']] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ $statusLabels[$entry['status']] ?? $entry['status'] }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Aucune traduction trouvée.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
        {{ $entries->count() }} entrées — Lecture seule. Les modifications doivent être faites dans les fichiers <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-gray-700 dark:bg-gray-700 dark:text-gray-300">lang/</code>.
    </p>
</x-admin-layout>
