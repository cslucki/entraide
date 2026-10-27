<x-admin-layout title="Historique de connexion">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Historique de connexion</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Connexions réussies des utilisateurs</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom ou email..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Toutes les organisations</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ request('organization_id') == $org->id ? 'selected' : '' }}>
                {{ $org->name }}
            </option>
            @endforeach
        </select>
        {{-- TASK-1694 bug AK — le menu de filtre par appareil.
             Quatre choix et non deux : les donnees reelles portent des
             connexions `curl` et `Symfony`, que ranger dans « ordinateur »
             serait faux. --}}
        <select name="device" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm"
                data-login-device-filter>
            <option value="">{{ __('admin.device_all') }}</option>
            @foreach($deviceKinds as $_kind)
                <option value="{{ $_kind->value }}" @selected($currentDevice === $_kind->value)>{{ __($_kind->labelKey()) }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'organization_id', 'sort', 'device']))
        <a href="{{ route('admin.stats.login-history') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.stats.login-history', array_merge(request()->query(), ['sort' => 'user', 'direction' => request('sort') === 'user' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Utilisateur
                            @if(request('sort') === 'user') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.stats.login-history', array_merge(request()->query(), ['sort' => 'organization_id', 'direction' => request('sort') === 'organization_id' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Organisation
                            @if(request('sort') === 'organization_id') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.stats.login-history', array_merge(request()->query(), ['sort' => 'ip_address', 'direction' => request('sort') === 'ip_address' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            IP
                            @if(request('sort') === 'ip_address') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    {{-- TASK-1694 bug AK — la categorie AVANT la chaine brute : elle se lit
                         d'un coup d'oeil, et la chaine reste la pour qui veut verifier. --}}
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.device_column') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Navigateur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.stats.login-history', array_merge(request()->query(), ['sort' => 'created_at', 'direction' => request('sort') === 'created_at' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Date
                            @if(request('sort') === 'created_at') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($loginLogs as $log)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            {{-- TASK-1712 — le compte peut avoir ete SUPPRIME.

                                `login_logs.user_id` est nullable et sa cle etrangere est en
                                `ON DELETE SET NULL` (migration du 24/09) : l'historique de
                                connexion survit deliberement au compte, c'est une trace. Ces
                                quatre lectures n'avaient aucune garde, et la PROD rendait 500
                                des la page 1 — son deuxieme enregistrement le plus recent est
                                orphelin. Le defaut etait identique en DEV, simplement repousse
                                en page 4 par l'ordre des donnees.

                                La ligne est CONSERVEE, pas masquee : un historique dont on
                                retire des lignes n'est plus un historique. L'IP, la date et
                                l'appareil restent lisibles, et l'identifiant brut permet de
                                relier l'evenement a une suppression. --}}
                            @if($log->user)
                            <img src="{{ $log->user->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                            <div class="min-w-0">
                                <a href="{{ route('admin.stats.login-history.user', $log->user) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 truncate">
                                    {{ $log->user->fullName }}
                                </a>
                                <p class="text-xs text-gray-500 truncate">{{ $log->user->email }}</p>
                                <p class="text-xs text-gray-400 font-mono">{{ $log->user->id }}</p>
                            </div>
                            @else
                            <div class="w-8 h-8 rounded-full flex-shrink-0 bg-gray-200 dark:bg-gray-600 flex items-center justify-center" aria-hidden="true">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            </div>
                            <div class="min-w-0" data-login-history-deleted-account>
                                <p class="font-medium text-gray-500 dark:text-gray-400 italic truncate">{{ __('admin.login_history_deleted_account') }}</p>
                                <p class="text-xs text-gray-400">{{ __('admin.login_history_deleted_account_hint') }}</p>
                            </div>
                            @endif
                        </div>
                    </td>
                    {{-- TASK-1694 bug AL — le SLUG plutot que le nom.
                         Demande de Cyril : « ainsi on gagne de la place ». Le nom
                         complet poussait les colonnes voisines, et la colonne
                         « Appareil » du bug AK vient d'en ajouter une.

                         Le nom reste accessible en INFOBULLE : un slug est court
                         mais pas toujours parlant, et le perdre entierement
                         obligerait a aller le chercher ailleurs. --}}
                    <td class="px-4 py-3">
                        @if($log->organization)
                        <span class="text-xs font-mono text-indigo-600 dark:text-indigo-400"
                              title="{{ $log->organization->name }}"
                              data-login-organization="{{ $log->organization->slug }}">{{ $log->organization->slug }}</span>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs text-gray-600 dark:text-gray-400 font-mono">{{ $log->ip_address ?? '—' }}</span>
                    </td>
                    @php($_device = \App\Support\Devices\DeviceKind::fromUserAgent($log->user_agent))
                    <td class="px-4 py-3 whitespace-nowrap text-xs">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 font-medium {{ match($_device) {
                                \App\Support\Devices\DeviceKind::Phone => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                \App\Support\Devices\DeviceKind::Tablet => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
                                \App\Support\Devices\DeviceKind::Desktop => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
                                default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                            } }}"
                              data-login-device="{{ $_device->value }}">
                            {{ __($_device->labelKey()) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-xs truncate" title="{{ $log->user_agent }}">
                        {{ $log->user_agent ? \Illuminate\Support\Str::limit($log->user_agent, 80) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $log->created_at?->format('d/m/Y H:i') ?? '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">Aucune connexion enregistrée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($loginLogs->hasPages())
    <div class="mt-4">{{ $loginLogs->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
