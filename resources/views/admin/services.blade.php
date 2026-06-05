<x-admin-layout title="Services">
    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Titre du service..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous les statuts</option>
            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Actifs</option>
            <option value="paused" {{ request('status') === 'paused' ? 'selected' : '' }}>En pause</option>
            <option value="deleted" {{ request('status') === 'deleted' ? 'selected' : '' }}>Supprimés</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('admin.services') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Service</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Auteur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Catégorie</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Points</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($services as $service)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $service->deleted_at ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $service->title }}</p>
                        <p class="text-xs text-gray-500 capitalize">{{ $service->delivery_mode }}</p>
                    </td>
                    <td class="px-4 py-3">
                        @if($service->user)
                        <a href="{{ route('profile.show', $service->user) }}" class="text-indigo-600 hover:underline text-xs">{{ $service->user->name }}</a>
                        @else
                        <span class="text-xs text-gray-400">Supprimé</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($service->category)
                        <span class="px-2 py-0.5 rounded-full text-xs text-white" style="background-color:{{ $service->category->color }}">
                            {{ $service->category->displayName('transactions') }}
                        </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium text-indigo-600 dark:text-indigo-400">{{ $service->points_cost }}</td>
                    <td class="px-4 py-3">
                        @php
                            $s = $service->deleted_at ? 'deleted' : $service->status;
                            $sc = ['active' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                   'paused' => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
                                   'deleted'=> 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'];
                            $sl = ['active' => 'Actif', 'paused' => 'Pause', 'deleted' => 'Supprimé'];
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc[$s] ?? '' }}">{{ $sl[$s] ?? $s }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $service->created_at->format('d/m/Y') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center">
                            @if(!$service->deleted_at)
                            <a href="{{ route('services.show', $service) }}" class="text-xs text-indigo-600 hover:underline">Voir</a>
                            <a href="{{ route('admin.services.edit', $service) }}" class="text-xs text-amber-600 dark:text-amber-400 hover:underline">Modifier</a>
                            <form method="POST" action="{{ route('admin.services.force-delete', $service->id) }}"
                                  onsubmit="return confirm('Supprimer définitivement ce service ?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Supprimer</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('admin.services.restore', $service->id) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-green-600 hover:underline">Restaurer</button>
                            </form>
                            <form method="POST" action="{{ route('admin.services.force-delete', $service->id) }}"
                                  onsubmit="return confirm('Supprimer définitivement ce service ?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Purger</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Aucun service trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($services->hasPages())
    <div class="mt-4">{{ $services->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
