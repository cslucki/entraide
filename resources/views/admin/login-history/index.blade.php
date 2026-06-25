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
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'organization_id', 'sort']))
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
                            <img src="{{ $log->user->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                            <div class="min-w-0">
                                <a href="{{ route('admin.stats.login-history.user', $log->user) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 truncate">
                                    {{ $log->user->name }}
                                </a>
                                <p class="text-xs text-gray-500 truncate">{{ $log->user->email }}</p>
                                <p class="text-xs text-gray-400 font-mono">{{ $log->user->id }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        @if($log->organization)
                        <span class="text-xs text-indigo-600 dark:text-indigo-400">{{ $log->organization->name }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs text-gray-600 dark:text-gray-400 font-mono">{{ $log->ip_address ?? '—' }}</span>
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
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">Aucune connexion enregistrée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($loginLogs->hasPages())
    <div class="mt-4">{{ $loginLogs->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
