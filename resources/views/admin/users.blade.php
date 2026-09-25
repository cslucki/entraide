<x-admin-layout title="Utilisateurs">

{{-- TASK-1640 — racine Alpine de la page.
     Une directive Alpine sans racine `x-data` sur un ancetre est inerte ET
     silencieuse : rien ne casse, le clic ne fait simplement rien. La racine est
     donc portee ici, autour de la table ET de la modal, et pas sur la modal
     seule — sinon `openDelete()` appele depuis une ligne ne trouverait pas
     l'etat. --}}
<div x-data="adminUserDelete()">
    {{-- TASK-1640 — bandeau de compteurs.
         Ils portent sur la POPULATION ENTIERE, pas sur le filtre courant : un
         compteur qui bougerait avec les filtres repondrait « combien en vois-je »
         au lieu de « combien y en a-t-il ». Le total filtre est affiche a cote du
         tableau, la ou il a du sens. Une SEULE requete agregee les produit tous. --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5">
        @foreach([
            ['label' => __('admin.users_stat_total'), 'value' => $stats['total'], 'accent' => 'text-gray-900 dark:text-gray-100', 'dot' => 'bg-gray-300 dark:bg-gray-600'],
            ['label' => __('admin.users_stat_available'), 'value' => $stats['disponibles'], 'accent' => 'text-emerald-600 dark:text-emerald-400', 'dot' => 'bg-emerald-500'],
            ['label' => __('admin.users_stat_admins'), 'value' => $stats['admins'], 'accent' => 'text-purple-600 dark:text-purple-400', 'dot' => 'bg-purple-500'],
            ['label' => __('admin.users_stat_banned'), 'value' => $stats['bannis'], 'accent' => 'text-red-600 dark:text-red-400', 'dot' => 'bg-red-500'],
            ['label' => __('admin.users_stat_new'), 'value' => $stats['nouveaux'], 'accent' => 'text-indigo-600 dark:text-indigo-400', 'dot' => 'bg-indigo-500'],
        ] as $stat)
        <div class="relative overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3 shadow-sm">
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1.5 h-1.5 rounded-full {{ $stat['dot'] }}"></span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 truncate">{{ $stat['label'] }}</span>
            </div>
            <p class="text-2xl font-bold tabular-nums {{ $stat['accent'] }}">{{ number_format($stat['value'], 0, ',', ' ') }}</p>
        </div>
        @endforeach
    </div>

    <div class="flex justify-end mb-4">
        <a href="{{ route('admin.users.create') }}"
           class="w-full sm:w-auto text-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
            + Créer un utilisateur
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-col sm:flex-row sm:flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom ou email..."
            class="w-full sm:flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous</option>
            <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Disponibles</option>
            <option value="banned" {{ request('status') === 'banned' ? 'selected' : '' }}>Bannis</option>
            <option value="admin" {{ request('status') === 'admin' ? 'selected' : '' }}>Admins</option>
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('admin.users_org_all') }}</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ request('organization_id') == $org->id ? 'selected' : '' }}>
                {{ $org->name }}
            </option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status', 'organization_id', 'sort']))
        <a href="{{ route('admin.users') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">Effacer</a>
        @endif
    </form>

    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
        {{ __('admin.users_results_count', ['count' => $users->total()]) }}
    </p>

    {{-- TASK-1640 — `overflow-hidden` COUPAIT les colonnes hors cadre : sur
         telephone, les dernieres colonnes (dont Actions) etaient inatteignables,
         pas seulement serrees. Le defilement horizontal est borne a ce conteneur,
         donc le corps de page ne defile jamais lateralement. --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        {{-- `min-w-[46rem]` n'est pas cosmetique : sans largeur minimale, la colonne
             Actions est ecrasee a ~130 px sur telephone et empile ses dix liens
             verticalement, ce qui porte chaque ligne a ~350 px — une ligne par
             ecran. Mesure a 375 px. Avec ce plancher, la colonne retrouve la place
             de disposer ses actions et les lignes reviennent a une hauteur normale ;
             le defilement horizontal, lui, est deja borne au conteneur. --}}
        <table class="w-full min-w-[46rem] text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'name', 'direction' => request('sort') === 'name' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Utilisateur
                            @if(request('sort') === 'name') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'organization_id', 'direction' => request('sort') === 'organization_id' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Organisation
                            @if(request('sort') === 'organization_id') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'points_balance', 'direction' => request('sort') === 'points_balance' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Points
                            @if(request('sort') === 'points_balance') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'services_count', 'direction' => request('sort') === 'services_count' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Services
                            @if(request('sort') === 'services_count') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'exchange_count', 'direction' => request('sort') === 'exchange_count' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Échanges
                            @if(request('sort') === 'exchange_count') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'rating', 'direction' => request('sort') === 'rating' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Note
                            @if(request('sort') === 'rating') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'status', 'direction' => request('sort') === 'status' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Statut
                            @if(request('sort') === 'status') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden sm:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'created_at', 'direction' => request('sort') === 'created_at' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Inscrit le
                            @if(request('sort') === 'created_at') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($users as $u)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 {{ $u->banned_at ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100 truncate">
                                    {{ $u->full_name }}
                                    @if($u->is_admin)<span class="ml-1 text-xs text-purple-600 dark:text-purple-400">[admin]</span>@endif
                                    @if($u->banned_at)<span class="ml-1 text-xs text-red-500">[banni]</span>@endif
                                </p>
                                <p class="text-xs text-gray-500 truncate">{{ $u->email }}</p>

                                {{-- TASK-1640 — sur mobile les colonnes Organisation et
                                     Points sont masquees : l'information ne doit pas etre
                                     PERDUE, elle remonte ici. Masquer une colonne sans
                                     rien mettre a la place aurait rendu l'ecran inutile
                                     sur telephone plutot que simplement etroit. --}}
                                <p class="md:hidden text-xs text-gray-500 truncate">
                                    {{ $u->organization?->name ?? 'Globale' }}
                                    <span class="lg:hidden">&middot; {{ $u->points_balance }} pts</span>
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="hidden md:table-cell px-4 py-3">
                        @if($u->organization)
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                            {{ $u->organization->name }}
                        </span>
                        @else
                        <span class="text-xs text-gray-400">Globale</span>
                        @endif
                    </td>
                    <td class="hidden lg:table-cell px-4 py-3">
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
                    <td class="hidden lg:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">{{ $u->services_count }}</td>
                    <td class="hidden xl:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">
                        {{ $u->buyer_transactions_count + $u->seller_transactions_count }}
                    </td>
                    <td class="hidden xl:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">
                        {{ $u->rating ? number_format($u->rating, 1).'/5' : '—' }}
                        @if($u->reviews_received_count > 0)
                        <span class="text-xs text-gray-400">({{ $u->reviews_received_count }})</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1 text-xs">
                            <span class="w-1.5 h-1.5 rounded-full {{ $u->banned_at ? 'bg-red-500' : ($u->is_available ? 'bg-green-500' : 'bg-gray-400') }}"></span>
                            {{ $u->banned_at ? 'Banni' : ($u->is_available ? 'Disponible' : 'Indisponible') }}
                        </span>
                    </td>
                    <td class="hidden sm:table-cell px-4 py-3 text-xs text-gray-400">{{ $u->created_at->format('d/m/Y') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center flex-wrap">
                            @if($u->id !== auth()->id() && !$u->banned_at)
                            <form method="POST" action="{{ route('admin.users.login-as', $u) }}">
                                @csrf
                                <button class="text-xs font-medium text-amber-600 hover:underline">
                                    Se connecter sous
                                </button>
                            </form>
                            @endif
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-xs font-medium text-indigo-600 hover:underline">Modifier</a>
                            <a href="{{ route('profile.show', $u) }}" class="text-xs text-gray-500 hover:underline">Profil</a>

                            {{-- TASK-1640 — la fiche complete, en pop-up. Aucun precheck ni
                                 comptage n'est calcule au rendu : le bouton ne porte que l'id. --}}
                            <button type="button"
                                @click="openProfile('{{ $u->id }}', @js($u->full_name))"
                                class="text-xs font-medium text-sky-600 hover:underline">
                                {{ __('admin.users_profile_button') }}
                            </button>

                            {{-- TASK-1640 — la suppression part d'ICI, plus d'un detour par Modifier.
                                 Le bouton ne porte aucun etat : il nomme le compte et laisse la modal
                                 partagee interroger le serveur. Aucun precheck n'est calcule au rendu. --}}
                            @if($u->id !== auth()->id())
                            <button type="button"
                                @click="openDelete('{{ $u->id }}', @js($u->fullName))"
                                class="text-xs font-medium text-red-600 hover:underline">
                                {{ __('admin.user_delete_row_button') }}
                            </button>
                            @endif

                            <form method="POST" action="{{ route('admin.users.toggle-availability', $u) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-gray-500 hover:text-orange-600">
                                    {{ $u->is_available ? __('admin.mark_unavailable') : __('admin.mark_available') }}
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
                                  onsubmit="return confirm('Bannir {{ addslashes($u->full_name) }} ?')">
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

                            <!-- Emailer -->
                            <a href="{{ route('admin.email-templates') }}"
                               class="text-xs text-gray-400 hover:text-indigo-500">
                                {{ __('admin.emailer_single_action') }}
                            </a>

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
                    <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-400">Aucun utilisateur trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
    <div class="mt-4">{{ $users->withQueryString()->links() }}</div>
    @endif

    {{-- ================================================================
         TASK-1640 — FICHE MEMBRE, une seule modal pour toute la page.
         Elle rend des COMPTAGES, jamais le contenu lui-meme : un ecran
         d'administration n'a pas a faire passer les messages ni les prompts IA
         d'une personne sous les yeux de l'admin pour repondre a « que fait-elle
         sur la plateforme ». C'est une decision, pas une limite technique.
         ================================================================ --}}
    <div x-show="profileOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="profileOpen = false">
        <div class="absolute inset-0 bg-black/50" @click="profileOpen = false"></div>

        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 px-5 sm:px-6 py-4 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 truncate"
                        x-text="'{{ __('admin.users_profile_title') }}'.replace(':name', profileName)"></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="profile?.identite?.email ?? ''"></p>
                </div>
                <button type="button" @click="profileOpen = false"
                    class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-sm">
                    {{ __('admin.users_profile_close') }}
                </button>
            </div>

            <div class="px-5 sm:px-6 py-5">
                <template x-if="profileLoading">
                    <p class="text-sm text-gray-500 dark:text-gray-400 py-6">{{ __('admin.users_profile_loading') }}</p>
                </template>

                <template x-if="! profileLoading && profileError">
                    <p class="text-sm text-red-600 py-6">{{ __('admin.users_profile_error') }}</p>
                </template>

                <template x-if="! profileLoading && ! profileError && profile">
                    <div class="space-y-6">
                        {{-- Identite --}}
                        <div>
                            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">{{ __('admin.users_profile_section_identity') }}</h4>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_org') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate"
                                       x-text="profile.identite.organization ?? '{{ __('admin.users_profile_org_none') }}'"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_status_label') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="profile.identite.statut"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_points') }}</p>
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100" x-text="profile.identite.points"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_registered') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="profile.identite.inscrit_le"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_last_login') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100"
                                       x-text="profile.identite.derniere_connexion ?? '{{ __('admin.users_profile_never') }}'"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_rating') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="profile.identite.note ?? '—'"></p>
                                </div>
                            </div>
                        </div>

                        {{-- Contributions et interactions : meme forme, deux sources. --}}
                        <template x-for="section in [
                            { titre: '{{ __('admin.users_profile_section_contributions') }}', data: profile.contributions },
                            { titre: '{{ __('admin.users_profile_section_interactions') }}', data: profile.interactions }
                        ]" :key="section.titre">
                            <div>
                                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2" x-text="section.titre"></h4>
                                <div class="rounded-xl border border-gray-100 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                                    <template x-for="[libelle, valeur] in Object.entries(section.data)" :key="libelle">
                                        <div class="flex items-center justify-between px-3 py-2">
                                            <span class="text-sm text-gray-600 dark:text-gray-300" x-text="libelle"></span>
                                            <span class="text-sm font-semibold tabular-nums"
                                                  :class="valeur > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-300 dark:text-gray-600'"
                                                  x-text="valeur"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- IA --}}
                        <div>
                            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">{{ __('admin.users_profile_section_ai') }}</h4>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                                <div class="rounded-lg border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/60 dark:bg-indigo-900/20 px-3 py-2">
                                    <p class="text-[11px] text-indigo-700 dark:text-indigo-300">{{ __('admin.users_profile_ai_calls') }}</p>
                                    <p class="text-lg font-bold tabular-nums text-indigo-700 dark:text-indigo-300" x-text="profile.ia.appels"></p>
                                </div>
                                <div class="rounded-lg border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/60 dark:bg-indigo-900/20 px-3 py-2">
                                    <p class="text-[11px] text-indigo-700 dark:text-indigo-300">{{ __('admin.users_profile_ai_tokens') }}</p>
                                    <p class="text-lg font-bold tabular-nums text-indigo-700 dark:text-indigo-300"
                                       x-text="new Intl.NumberFormat('{{ app()->getLocale() }}').format(profile.ia.tokens)"></p>
                                </div>
                                <div class="rounded-lg border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/60 dark:bg-indigo-900/20 px-3 py-2">
                                    <p class="text-[11px] text-indigo-700 dark:text-indigo-300">{{ __('admin.users_profile_ai_cost') }}</p>
                                    <p class="text-lg font-bold tabular-nums text-indigo-700 dark:text-indigo-300" x-text="profile.ia.cout"></p>
                                </div>
                            </div>
                            <div class="rounded-xl border border-gray-100 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                                <template x-for="[libelle, valeur] in [
                                    ['{{ __('admin.users_profile_ai_interactions') }}', profile.ia.interactions],
                                    ['{{ __('admin.users_profile_ai_shell') }}', profile.ia.shell],
                                    ['{{ __('admin.users_profile_ai_feedback') }}', profile.ia.retours]
                                ]" :key="libelle">
                                    <div class="flex items-center justify-between px-3 py-2">
                                        <span class="text-sm text-gray-600 dark:text-gray-300" x-text="libelle"></span>
                                        <span class="text-sm font-semibold tabular-nums"
                                              :class="valeur > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-300 dark:text-gray-600'"
                                              x-text="valeur"></span>
                                    </div>
                                </template>
                                <div class="flex items-center justify-between px-3 py-2">
                                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('admin.users_profile_ai_profile_label') }}</span>
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                                          :class="profile.ia.profil_ia ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                                          x-text="profile.ia.profil_ia ? '{{ __('admin.users_profile_ai_profile_yes') }}' : '{{ __('admin.users_profile_ai_profile_no') }}'"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ================================================================
         TASK-1640 — UNE seule modal pour toute la page.
         Une modal par ligne aurait duplique 20 fois le meme balisage et
         20 fois le meme etat Alpine.
         ================================================================ --}}
    <div x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="close()">
        <div class="absolute inset-0 bg-black/50" @click="close()"></div>

        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-lg w-full p-5 sm:p-6 max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2"
                x-text="'{{ __('admin.user_delete_modal_title') }}'.replace(':name', name)"></h3>

            {{-- Etat 0 : on interroge le serveur. --}}
            <template x-if="loading">
                <p class="text-sm text-gray-500 dark:text-gray-400 py-4">{{ __('admin.user_delete_modal_checking') }}</p>
            </template>

            <template x-if="! loading && error">
                <p class="text-sm text-red-600 py-4" x-text="error"></p>
            </template>

            {{-- Etat C : BLOCK. Les messages metier du serveur, et AUCUN bouton
                 de suppression : un bouton qu'on sait impossible est un piege. --}}
            <template x-if="! loading && ! error && blocks.length > 0">
                <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('admin.user_delete_blocked_title') }}</p>
                    <ul class="space-y-2 mb-6">
                        <template x-for="block in blocks" :key="block.message">
                            <li class="text-sm text-gray-600 dark:text-gray-400 flex gap-2">
                                <span class="text-red-500">&bull;</span><span x-text="block.message"></span>
                            </li>
                        </template>
                    </ul>
                    <div class="flex justify-end">
                        <button type="button" @click="close()"
                            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                            {{ __('admin.user_delete_modal_cancel') }}
                        </button>
                    </div>
                </div>
            </template>

            {{-- Etats A et B : la suppression est possible. --}}
            <template x-if="! loading && ! error && blocks.length === 0">
                <form method="POST" :action="destroyUrl">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="preview_fingerprint" :value="fingerprint">

                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">{{ __('admin.user_delete_modal_body') }}</p>

                    {{-- Le caractere DEFINITIF est dit a part et mis en avant : noye
                         dans le paragraphe precedent, il se lit comme une precision
                         alors que c'est la seule information qu'on ne peut pas
                         rattraper apres coup. --}}
                    <p class="flex items-start gap-2 text-sm font-medium text-red-600 dark:text-red-400 mb-4">
                        <span aria-hidden="true">&#9888;</span>
                        <span>{{ __('admin.user_delete_modal_irreversible') }}</span>
                    </p>

                    {{-- B : transfert necessaire. UNIQUEMENT le choix du repreneur :
                         aucune mention de table, de contrainte ni d'organisation technique. --}}
                    <template x-if="requiresTransfer">
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
                                   x-text="'{{ __('admin.user_delete_modal_transfer_label') }}'.replace(':count', transferTotal)"></label>
                            <select name="transfer_to" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                                <option value="">{{ __('admin.user_delete_modal_transfer_placeholder') }}</option>
                                <template x-for="c in candidates" :key="c.id">
                                    <option :value="c.id" x-text="c.name"></option>
                                </template>
                            </select>
                            <template x-if="candidates.length === 0">
                                <p class="text-xs text-red-600 mt-1">{{ __('admin.user_delete_modal_transfer_none') }}</p>
                            </template>
                        </div>
                    </template>

                    <div class="flex gap-3 justify-end">
                        <button type="button" @click="close()"
                            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                            {{ __('admin.user_delete_modal_cancel') }}
                        </button>
                        <button type="submit"
                            :disabled="requiresTransfer && candidates.length === 0"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium"
                            x-text="requiresTransfer ? '{{ __('admin.user_delete_modal_confirm_transfer') }}' : '{{ __('admin.user_delete_modal_confirm') }}'"></button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function adminUserDelete() {
        return {
            open: false,
            loading: false,
            error: null,
            name: '',
            destroyUrl: '',
            blocks: [],
            requiresTransfer: false,
            transferTotal: 0,
            candidates: [],
            fingerprint: '',

            profileOpen: false,
            profileLoading: false,
            profileError: false,
            profileName: '',
            profile: null,

            close() { this.open = false; },

            async openProfile(id, name) {
                this.profileOpen = true;
                this.profileLoading = true;
                this.profileError = false;
                this.profileName = name;
                // Remis a null a CHAQUE ouverture : sinon la fiche afficherait un
                // instant les chiffres du membre precedent, ce qui est pire qu'un
                // ecran vide sur un ecran d'administration.
                this.profile = null;

                try {
                    const response = await fetch(
                        '{{ route('admin.users.profile-summary', ['user' => '__ID__']) }}'.replace('__ID__', id),
                        { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
                    );

                    if (! response.ok) { throw new Error(response.status); }

                    this.profile = await response.json();
                } catch (e) {
                    this.profileError = true;
                } finally {
                    this.profileLoading = false;
                }
            },

            async openDelete(id, name) {
                // L'etat est remis a zero a CHAQUE ouverture : sans cela, la
                // modal afficherait un instant les blocages du compte precedent.
                this.open = true;
                this.loading = true;
                this.error = null;
                this.name = name;
                this.blocks = [];
                this.requiresTransfer = false;
                this.transferTotal = 0;
                this.candidates = [];
                this.fingerprint = '';
                this.destroyUrl = '{{ route('admin.users.destroy', ['user' => '__ID__']) }}'.replace('__ID__', id);

                try {
                    const response = await fetch(
                        '{{ route('admin.users.delete-precheck', ['user' => '__ID__']) }}'.replace('__ID__', id),
                        { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
                    );

                    if (! response.ok) { throw new Error(response.status); }

                    const data = await response.json();

                    this.blocks = data.blocks;
                    this.requiresTransfer = data.requires_transfer;
                    this.transferTotal = data.transfer_total;
                    this.candidates = data.transfer_candidates;
                    // L'empreinte vient du serveur, calculee AU CLIC : c'est elle
                    // qui rend une decision perimee refusable.
                    this.fingerprint = data.preview_fingerprint;
                } catch (e) {
                    this.error = '{{ __('admin.user_delete_modal_error') }}';
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endpush
</x-admin-layout>
