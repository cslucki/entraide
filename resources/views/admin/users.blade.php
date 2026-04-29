<x-app-layout title="Admin — Utilisateurs">
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <a href="{{ route('admin.dashboard') }}" class="text-sm text-gray-500 hover:text-indigo-600">← Admin</a>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">Utilisateurs</h1>
            </div>
        </div>

        <!-- Search -->
        <form method="GET" class="mb-6 flex gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Rechercher par nom ou email..."
                class="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Chercher</button>
            @if(request('search'))
            <a href="{{ route('admin.users') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">Effacer</a>
            @endif
        </form>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Utilisateur</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Points</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Services</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Échanges</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Note</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($users as $u)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $u->name }}</p>
                                    <p class="text-xs text-gray-500 truncate">{{ $u->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $u->points_balance }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $u->services_count }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $u->buyer_transactions_count + $u->seller_transactions_count }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $u->rating ? number_format($u->rating, 1).'/5' : '—' }}
                            @if($u->reviews_received_count > 0)
                            <span class="text-xs text-gray-400">({{ $u->reviews_received_count }})</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-1">
                                <span class="inline-flex items-center gap-1 text-xs">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $u->is_available ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                    {{ $u->is_available ? 'Disponible' : 'Indisponible' }}
                                </span>
                                @if($u->is_admin)
                                <span class="text-xs text-purple-600 dark:text-purple-400 font-medium">Admin</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-3 items-center flex-wrap">
                                <a href="{{ route('profile.show', $u) }}" class="text-xs text-indigo-600 hover:underline">Profil</a>
                                <form method="POST" action="{{ route('admin.users.toggle-availability', $u) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs text-gray-500 hover:text-orange-600">
                                        {{ $u->is_available ? 'Désactiver' : 'Activer' }}
                                    </button>
                                </form>
                                @if($u->id !== auth()->id())
                                <form method="POST" action="{{ route('admin.users.toggle-admin', $u) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs {{ $u->is_admin ? 'text-purple-600 hover:text-red-600' : 'text-gray-400 hover:text-purple-600' }}">
                                        {{ $u->is_admin ? 'Retirer admin' : 'Rendre admin' }}
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Aucun utilisateur trouvé.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($users->hasPages())
        <div class="mt-4">
            {{ $users->withQueryString()->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
