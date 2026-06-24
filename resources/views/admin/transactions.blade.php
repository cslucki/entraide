<x-admin-layout title="Transactions">
    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom acheteur ou vendeur..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous les statuts</option>
            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>En attente</option>
            <option value="accepted" {{ request('status') === 'accepted' ? 'selected' : '' }}>Acceptées</option>
            <option value="buyer_done" {{ request('status') === 'buyer_done' ? 'selected' : '' }}>Buyer done</option>
            <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Complétées</option>
            <option value="refused" {{ request('status') === 'refused' ? 'selected' : '' }}>Refusées</option>
            <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Annulées</option>
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>Toutes les organisations</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status', 'organization_id']))
        <a href="{{ route('admin.transactions') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Sujet</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Acheteur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Vendeur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Points</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($transactions as $tx)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100 max-w-xs truncate">{{ $tx->subject }}</p>
                        <p class="text-xs text-gray-500 font-mono">{{ substr($tx->id, 0, 8) }}…</p>
                    </td>
                    <td class="px-4 py-3">
                        @if($tx->buyer)
                        <a href="{{ route('profile.show', $tx->buyer) }}" class="text-indigo-600 hover:underline text-xs">{{ $tx->buyer->name }}</a>
                        @else <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($tx->seller)
                        <a href="{{ route('profile.show', $tx->seller) }}" class="text-indigo-600 hover:underline text-xs">{{ $tx->seller->name }}</a>
                        @else <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $tx->organization?->name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ $tx->points_agreed ?? $tx->points_proposed }}</span>
                        @if($tx->points_agreed && $tx->points_agreed !== $tx->points_proposed)
                        <span class="text-xs text-gray-400 line-through ml-1">{{ $tx->points_proposed }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $colors = [
                                'pending'    => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
                                'accepted'   => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
                                'buyer_done' => 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
                                'completed'  => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                'refused'    => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
                                'cancelled'  => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
                            ];
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $colors[$tx->status] ?? '' }}">{{ $tx->status_label }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('admin.transactions.destroy', $tx->id) }}"
                              onsubmit="return confirm('{{ __('admin.transaction_delete_confirm') }}')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">Supprimer</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">Aucune transaction trouvée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($transactions->hasPages())
    <div class="mt-4">{{ $transactions->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
