<x-admin-layout title="Fichiers — {{ $loop->name }}">
    <div class="max-w-4xl">
        <a href="{{ route('admin.loops') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour aux boucles</a>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-2 mb-1">{{ $loop->name }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Fichiers partagés dans cette boucle</p>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Fichier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Message</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($messages as $message)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                            <td class="px-4 py-3">
                                <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ Str::limit($message->body, 80) }}</p>
                            </td>
                            <td class="px-4 py-3 hidden sm:table-cell">
                                @if($message->metadata)
                                    <span class="text-xs text-gray-500">{{ $message->type }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                @if($message->sender)
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ $message->sender->name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-xs text-gray-500 whitespace-nowrap">
                                {{ $message->created_at->format('d/m/Y H:i') }}
                            </td>
                        </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Aucun fichier partagé dans cette boucle.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($messages->hasPages())
        <div class="mt-4">{{ $messages->links() }}</div>
        @endif
    </div>
</x-admin-layout>
