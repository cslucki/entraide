<x-admin-layout title="Demandes plateforme">
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Demandes publiques de mise à disposition de la plateforme sur un autre serveur. Ces demandes ne sont pas tenant-scopées.
        </p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom, contact, email, téléphone..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous les statuts</option>
            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Nouvelles</option>
            <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approuvées</option>
            <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejetées</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('admin.organization-requests') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Demande</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Contact</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Contexte</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($requests as $organizationRequest)
                @php
                    $statusColors = [
                        'pending' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
                        'approved' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                        'rejected' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                    ];
                    $statusLabels = ['pending' => 'Nouvelle', 'approved' => 'Approuvée', 'rejected' => 'Rejetée'];
                @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $organizationRequest->boucle_name }}</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 max-w-xl">{{ Str::limit($organizationRequest->description, 180) }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $organizationRequest->contact_name }}</p>
                        <a href="mailto:{{ $organizationRequest->contact_email }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ $organizationRequest->contact_email }}</a>
                        @if($organizationRequest->contact_phone)
                        <p class="mt-1 text-xs text-gray-500">{{ $organizationRequest->contact_phone }}</p>
                        @endif
                        @if($organizationRequest->website_url)
                        <a href="{{ $organizationRequest->website_url }}" target="_blank" rel="noopener" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline block mt-0.5">Site web</a>
                        @endif
                        @if($organizationRequest->user)
                        <p class="mt-1 text-[11px] text-gray-400">Compte lié : {{ $organizationRequest->user->fullName }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-600 dark:text-gray-400 max-w-xs">
                        {{ $organizationRequest->context ?: '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded text-xs font-medium {{ $statusColors[$organizationRequest->status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $statusLabels[$organizationRequest->status] ?? $organizationRequest->status }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-500 whitespace-nowrap">
                        {{ $organizationRequest->created_at->format('d/m/Y H:i') }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">Aucune demande plateforme trouvée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($requests->hasPages())
    <div class="mt-4">{{ $requests->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
