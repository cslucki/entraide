<x-org-admin-layout :title="'Connexions — '.$user->fullName" :organization="$organization">
    <div class="mb-6">
        <a href="{{ route('organization.admin.stats.login-history', $organization) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour à l'historique</a>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">Connexions de {{ $user->fullName }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $user->email }}</p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">IP</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Navigateur</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 font-mono">{{ $log->ip_address ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-sm truncate" title="{{ $log->user_agent }}">{{ $log->user_agent ? \Illuminate\Support\Str::limit($log->user_agent, 120) : '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-400">Aucune connexion trouvée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
    <div class="mt-4">{{ $logs->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
