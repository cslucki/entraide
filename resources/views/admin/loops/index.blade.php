<x-admin-layout title="Boucles">
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
        Boucles actives dans votre Organisation.
    </p>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Nom</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">Créateur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membres</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Dernière activité</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($loops as $orgLoop)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $orgLoop->name }}</p>
                            @if($orgLoop->description)
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-xs">{{ Str::limit($orgLoop->description, 80) }}</p>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $orgLoop->type === 'team' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : '' }}
                            {{ $orgLoop->type === 'project' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200' : '' }}
                            {{ $orgLoop->type === 'social' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : '' }}
                            {{ !in_array($orgLoop->type, ['team', 'project', 'social']) ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : '' }}">
                            {{ $orgLoop->type }}
                        </span>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        @if($orgLoop->status === 'active')
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
                        @elseif($orgLoop->status === 'archived')
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Archivée</span>
                        @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">{{ $orgLoop->status }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell text-xs text-gray-600 dark:text-gray-400">
                        @if($orgLoop->creator)
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $orgLoop->creator->name }}</p>
                        <p class="text-xs text-gray-500 truncate max-w-[140px]">{{ $orgLoop->creator->email }}</p>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $orgLoop->active_members_count }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400"> membre{{ $orgLoop->active_members_count !== 1 ? 's' : '' }}</span>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        @php $lastMsg = $orgLoop->messages->first(); @endphp
                        @if($lastMsg?->created_at)
                        {{ $lastMsg->created_at->diffForHumans() }}
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        Aucune boucle dans cette Organisation.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($loops->hasPages())
    <div class="mt-4">
        {{ $loops->links() }}
    </div>
    @endif
</x-admin-layout>
