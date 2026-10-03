<x-admin-layout title="Utilisateurs">

{{-- TASK-1640 — racine Alpine de la page.
     Une directive Alpine sans racine `x-data` sur un ancetre est inerte ET
     silencieuse : rien ne casse, le clic ne fait simplement rien. La racine est
     donc portee ici, autour de la table ET de la modal, et pas sur la modal
     seule — sinon une directive Alpine appelee depuis une ligne ne trouverait pas
     l'etat. --}}
{{-- TASK-1668 — plus de composant Alpine de suppression ici : la page
     `admin/users/delete.blade.php` a pris le relais. Cette racine reste
     parce que des directives Alpine subsistent plus bas (menus replies)
     et qu'une directive sans racine est inerte ET silencieuse. --}}
<div x-data="{}">
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
                            {{-- TASK-1666 — `profile.show` est une route FRONT, bornee au tenant
                                 courant : elle fait `abort(404)` des que `organization_id`
                                 differe, et aussi sur un compte banni. Un SuperAdmin n'y
                                 echappe pas, et c'est VOULU — la garde de tenant n'est pas a
                                 percer. On n'affiche donc le lien que la ou il MENE quelque
                                 part ; ailleurs la Fiche, elle, marche toujours. --}}
                            @if($u->organization_id !== null
                                && optional(currentOrganization())->id === $u->organization_id
                                && $u->banned_at === null)
                            <a href="{{ route('profile.show', $u) }}" class="text-xs text-gray-500 hover:underline">Profil</a>
                            @endif

                            {{-- TASK-1640 — la fiche complete, en pop-up. Aucun precheck ni
                                 comptage n'est calcule au rendu : le bouton ne porte que l'id. --}}
                            <button type="button"
                                @click="$dispatch('open-user-profile', { id: '{{ $u->id }}', name: @js($u->full_name) })"
                                class="text-xs font-medium text-sky-600 hover:underline">
                                {{ __('admin.users_profile_button') }}
                            </button>

                            {{-- TASK-1640 — la suppression part d'ICI, plus d'un detour par
                                 Modifier. TASK-1668 — elle mene desormais a une PAGE : la modal
                                 avait grossi a chaque TASK et debordait. Le lien ne porte
                                 toujours AUCUN etat, et aucun precheck n'est calcule au rendu de
                                 la liste. --}}
                            @if($u->id !== auth()->id())
                            <a href="{{ route('admin.users.delete-page', $u) }}"
                               class="text-xs font-medium text-red-600 hover:underline">
                                {{ __('admin.user_delete_row_button') }}
                            </a>
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

    {{-- TASK-1666 — la Fiche a quitte ce fichier pour
         `admin/partials/user-profile-modal.blade.php` : /admin/transactions
         en a besoin aussi. Elle porte sa propre racine Alpine et s'ouvre
         par evenement, d'ou le `$dispatch` sur le bouton plus haut. --}}
    @include('admin.partials.user-profile-modal')

    {{-- TASK-1668 — la modal de suppression a laisse place a une PAGE
         (`admin/users/delete.blade.php`). Elle debordait : blocages avec leurs
         liens, contenus a confier par famille avec les leurs, choix du repreneur.
         Les deux ne coexistent pas : garder un raccourci modal doublerait la
         surface a tester pour la meme garantie. --}}
</div>

</x-admin-layout>
