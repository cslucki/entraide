<x-admin-layout title="Utilisateurs">
    <div class="flex justify-end mb-4">
        <a href="{{ route('admin.users.create') }}"
           class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
            + Créer un utilisateur
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom ou email..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous</option>
            <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Disponibles</option>
            <option value="banned" {{ request('status') === 'banned' ? 'selected' : '' }}>Bannis</option>
            <option value="admin" {{ request('status') === 'admin' ? 'selected' : '' }}>Admins</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('admin.users') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Utilisateur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Organisation</th>
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
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $u->banned_at ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100 truncate">
                                    {{ $u->name }}
                                    @if($u->is_admin)<span class="ml-1 text-xs text-purple-600 dark:text-purple-400">[admin]</span>@endif
                                    @if($u->banned_at)<span class="ml-1 text-xs text-red-500">[banni]</span>@endif
                                </p>
                                <p class="text-xs text-gray-500 truncate">{{ $u->email }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        @if($u->organization)
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                            {{ $u->organization->name }}
                        </span>
                        @else
                        <span class="text-xs text-gray-400">Globale</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="text-gray-700 dark:text-gray-300 hover:text-indigo-600 font-medium">{{ $u->points_balance }}</button>
                            <div x-show="open" x-cloak @click.outside="open = false"
                                 class="absolute left-0 mt-1 w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-10 p-3">
                                <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Ajuster les points</p>
                                <form method="POST" action="{{ route('admin.users.adjust-points', $u) }}" class="flex gap-2">
                                    @csrf
                                    <input type="number" name="delta" placeholder="±pts" required
                                        class="flex-1 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                    <button type="submit" class="px-2 py-1 bg-indigo-600 text-white text-xs rounded hover:bg-indigo-700">OK</button>
                                </form>
                                <p class="text-xs text-gray-400 mt-1">Entrez un nombre positif ou négatif.</p>
                            </div>
                        </div>
                    </td>
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
                                <span class="w-1.5 h-1.5 rounded-full {{ $u->banned_at ? 'bg-red-500' : ($u->is_available ? 'bg-green-500' : 'bg-gray-400') }}"></span>
                                {{ $u->banned_at ? 'Banni' : ($u->is_available ? 'Disponible' : 'Indisponible') }}
                            </span>
                            <span class="text-xs text-gray-400">Inscrit {{ $u->created_at->format('d/m/Y') }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center flex-wrap">
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-xs font-medium text-indigo-600 hover:underline">Modifier</a>
                            <a href="{{ route('profile.show', $u) }}" class="text-xs text-gray-500 hover:underline">Profil</a>

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
                                    {{ $u->is_admin ? '−Admin' : '+Admin' }}
                                </button>
                            </form>

                            @if($u->banned_at)
                            <form method="POST" action="{{ route('admin.users.unban', $u) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-green-600 hover:underline">Débannir</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('admin.users.ban', $u) }}"
                                  onsubmit="return confirm('Bannir {{ addslashes($u->name) }} ?')">
                                @csrf @method('PATCH')
                                <button class="text-xs text-red-500 hover:underline">Bannir</button>
                            </form>
                            @endif
                            @endif

                            <!-- Affecter à une organisation -->
                            @if($u->id !== auth()->id())
                            <div x-data="{ commOpen: false }" class="relative">
                                <button @click="commOpen = !commOpen"
                                        class="text-xs text-gray-400 hover:text-indigo-500">Organisation</button>
                                <div x-show="commOpen" x-cloak @click.outside="commOpen = false"
                                     class="absolute right-0 mt-1 w-64 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-10 p-3">
                                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Affecter à une organisation
                                    </p>
                                    <form method="POST" action="{{ route('admin.users.assign-organization', $u) }}">
                                        @csrf @method('PATCH')
                                        <select name="organization_id"
                                                class="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 mb-2">
                                            <option value="">— Organisation par defaut de la plateforme —</option>
                                            @foreach(\App\Models\Organization::where('is_active', true)->get() as $organization)
                                            <option value="{{ $organization->id }}" {{ $u->organization_id === $organization->id ? 'selected' : '' }}>
                                                {{ $organization->name }}
                                            </option>
                                            @endforeach
                                        </select>
                                        <button type="submit"
                                                class="w-full px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded transition">
                                            Affecter
                                        </button>
                                    </form>
                                </div>
                            </div>
                            @endif

                            <!-- Envoyer lien de réinitialisation -->
                            <form method="POST" action="{{ route('admin.users.send-password-reset', $u) }}"
                                  onsubmit="return confirm('Envoyer un lien de réinitialisation à ce membre ?')">
                                @csrf
                                <button class="text-xs text-gray-400 hover:text-indigo-500">
                                    Lien de réinitialisation
                                </button>
                            </form>

                            <!-- Changer le mot de passe -->
                            <div x-data="{ pwOpen: false }" class="relative">
                                <button @click="pwOpen = !pwOpen"
                                        class="text-xs text-gray-400 hover:text-orange-500">Mdp</button>
                                <div x-show="pwOpen" x-cloak @click.outside="pwOpen = false"
                                     class="absolute right-0 mt-1 w-60 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-10 p-3">
                                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Changer le mot de passe
                                    </p>
                                    <form method="POST" action="{{ route('admin.users.password', $u) }}"
                                          class="space-y-2">
                                        @csrf
                                        <input type="password" name="password" placeholder="Nouveau mdp"
                                               required minlength="8"
                                               class="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                        <input type="password" name="password_confirmation" placeholder="Confirmer"
                                               required minlength="8"
                                               class="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                        <button type="submit"
                                                class="w-full px-2 py-1 bg-orange-600 hover:bg-orange-700 text-white text-xs rounded transition">
                                            Changer
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">Aucun utilisateur trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
    <div class="mt-4">{{ $users->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
