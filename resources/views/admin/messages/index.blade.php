<x-admin-layout title="Messages">
    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="user" value="{{ request('user') }}" placeholder="Expéditeur ou destinataire..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Mot-clé dans le contenu..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <input type="date" name="date_from" value="{{ request('date_from') }}"
            class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
        <input type="date" name="date_to" value="{{ request('date_to') }}"
            class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['user', 'search', 'date_from', 'date_to']))
        <a href="{{ route('admin.messages') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Expéditeur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Participants</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Contenu</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($messages as $message)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $message->type === 'system' ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $message->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        @if($message->sender)
                        <p class="font-medium text-gray-900 dark:text-gray-100 text-xs">{{ $message->sender->name }}</p>
                        <p class="text-xs text-gray-500 truncate max-w-[120px]">{{ $message->sender->email }}</p>
                        @else
                        <span class="text-xs text-gray-400 italic">Système</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                        @if($message->transaction)
                        <span class="block">{{ $message->transaction->buyer->name ?? '?' }}</span>
                        <span class="text-gray-400">↔</span>
                        <span class="block">{{ $message->transaction->seller->name ?? '?' }}</span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-xs">
                        <p class="truncate">{{ Str::limit($message->body, 100) }}</p>
                        @if($message->type === 'system')
                        <span class="text-xs text-gray-400 italic">message système</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-3 items-center">
                            <a href="{{ route('admin.messages.show', $message) }}"
                               class="text-xs text-indigo-600 hover:underline">Détail</a>
                            <form method="POST" action="{{ route('admin.messages.destroy', $message) }}"
                                  onsubmit="return confirm('Supprimer définitivement ce message ?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">Aucun message trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($messages->hasPages())
    <div class="mt-4">{{ $messages->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
