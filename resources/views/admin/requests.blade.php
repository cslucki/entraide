<x-admin-layout title="Demandes">
    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Titre de la demande..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous les statuts</option>
            <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>Ouvertes</option>
            <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>En cours</option>
            <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Clôturées</option>
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>Toutes les organisations</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status', 'organization_id']))
        <a href="{{ route('admin.requests') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Demande</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Auteur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Catégorie</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Budget</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($requests as $req)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <a href="{{ route('requests.show', $req) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $req->title }}</a>
                        @if($req->deadline)
                        <p class="text-xs text-gray-400">Avant le {{ $req->deadline->format('d/m/Y') }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($req->user)
                        <a href="{{ route('profile.show', $req->user) }}" class="text-indigo-600 hover:underline text-xs">{{ $req->user->name }}</a>
                        @else <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $req->organization?->name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @if($req->category)
                        <span class="px-2 py-0.5 rounded-full text-xs text-white" style="background-color:{{ $req->category->color }}">
                            {{ $req->category->displayName('transactions') }}
                        </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 text-xs">
                        {{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $sc = ['open' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                   'in_progress' => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
                                   'closed' => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400'];
                            $sl = ['open' => 'Ouverte', 'in_progress' => 'En cours', 'closed' => 'Clôturée'];
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc[$req->status] ?? '' }}">{{ $sl[$req->status] ?? $req->status }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center">
                            <a href="{{ route('admin.requests.edit', $req) }}" class="text-xs text-amber-600 dark:text-amber-400 hover:underline">Modifier</a>
                            @if($req->status !== 'closed')
                            <form method="POST" action="{{ route('admin.requests.close', $req) }}"
                                  onsubmit="return confirm('Clôturer cette demande ?')">
                                @csrf @method('PATCH')
                                <button class="text-xs text-orange-500 hover:underline">Clôturer</button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('admin.requests.destroy', $req->id) }}"
                                  onsubmit="return confirm('{{ __('admin.request_delete_confirm') }}')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-600 hover:underline">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Aucune demande trouvée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($requests->hasPages())
    <div class="mt-4">{{ $requests->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
