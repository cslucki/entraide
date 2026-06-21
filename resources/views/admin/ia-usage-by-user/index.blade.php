<x-admin-layout>
    <x-slot name="title">Utilisation IA par utilisateur</x-slot>

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold dark:text-white">Utilisation IA par utilisateur</h1>
        </div>

        {{-- Filtres --}}
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Organisation</label>
                <select name="organization_id"
                        class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
                    <option value="">Toutes</option>
                    @foreach($organizations as $org)
                        <option value="{{ $org->id }}" @selected(request('organization_id') == $org->id)>
                            {{ $org->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Du</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                       class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Au</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                       class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Recherche</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Nom ou email…"
                       class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                    Filtrer
                </button>
                <a href="{{ route('admin.ia-usage-by-user') }}"
                   class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Réinitialiser
                </a>
            </div>
        </form>

        {{-- Tableau --}}
        <div class="overflow-x-auto bg-white dark:bg-gray-800 rounded-lg shadow">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <th class="px-4 py-3">
                            <a href="{{ route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction']), ['sort' => 'user_id', 'direction' => request('sort') === 'user_id' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}"
                               class="flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                Utilisateur
                                @if(request('sort') === 'user_id')
                                    <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th class="px-4 py-3">Organisation</th>
                        <th class="px-4 py-3 text-right">
                            <a href="{{ route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction']), ['sort' => 'total_interactions', 'direction' => request('sort') === 'total_interactions' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}"
                               class="flex items-center gap-1 justify-end hover:text-gray-700 dark:hover:text-gray-200">
                                Requêtes
                                @if(request('sort') === 'total_interactions')
                                    <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <a href="{{ route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction']), ['sort' => 'total_input_tokens', 'direction' => request('sort') === 'total_input_tokens' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}"
                               class="flex items-center gap-1 justify-end hover:text-gray-700 dark:hover:text-gray-200">
                                Tokens in
                                @if(request('sort') === 'total_input_tokens')
                                    <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <a href="{{ route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction']), ['sort' => 'total_output_tokens', 'direction' => request('sort') === 'total_output_tokens' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}"
                               class="flex items-center gap-1 justify-end hover:text-gray-700 dark:hover:text-gray-200">
                                Tokens out
                                @if(request('sort') === 'total_output_tokens')
                                    <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <a href="{{ route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction']), ['sort' => 'total_cost', 'direction' => request('sort') === 'total_cost' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}"
                               class="flex items-center gap-1 justify-end hover:text-gray-700 dark:hover:text-gray-200">
                                Coût
                                @if(request('sort') === 'total_cost')
                                    <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-right">
                            <a href="{{ route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction']), ['sort' => 'last_interaction', 'direction' => request('sort') === 'last_interaction' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}"
                               class="flex items-center gap-1 justify-end hover:text-gray-700 dark:hover:text-gray-200">
                                Dernière
                                @if(request('sort') === 'last_interaction')
                                    <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($interactions as $row)
                        @php $user = $row->user; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition">
                            <td class="px-4 py-3">
                                @if($user)
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $user->email }}</div>
                                @else
                                    <span class="text-gray-400 italic">Utilisateur supprimé</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                {{ $user?->organization?->name ?? ($row->organization_id ? '#' . $row->organization_id : '-') }}
                            </td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-gray-100 font-mono text-xs">
                                {{ number_format($row->total_interactions) }}
                            </td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-gray-100 font-mono text-xs">
                                {{ number_format($row->total_input_tokens) }}
                            </td>
                            <td class="px-4 py-3 text-right text-gray-900 dark:text-gray-100 font-mono text-xs">
                                {{ number_format($row->total_output_tokens) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if((float) $row->total_cost > 0)
                                    <span class="text-gray-900 dark:text-gray-100">${{ number_format((float) $row->total_cost, 6) }}</span>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-xs text-gray-500">
                                {{ $row->last_interaction ? $row->last_interaction->format('d/m/Y H:i') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                                Aucune utilisation IA trouvée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="flex justify-center">
            {{ $interactions->links() }}
        </div>
    </div>
</x-admin-layout>
