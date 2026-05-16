<x-admin-layout title="Messages">
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
        Messages liés à votre Organisation.
    </p>

    <div class="mb-5 flex gap-1 bg-gray-100 dark:bg-gray-800 rounded-lg p-1 w-fit">
        <a href="{{ route('admin.messages', ['filter' => 'chatloop']) }}"
           class="px-4 py-2 text-sm rounded-md transition
                  {{ $filter === 'chatloop' ? 'bg-white dark:bg-gray-700 shadow-sm font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
            ChatLoop
        </a>
        <a href="{{ route('admin.messages', ['filter' => 'exchanges']) }}"
           class="px-4 py-2 text-sm rounded-md transition
                  {{ $filter === 'exchanges' ? 'bg-white dark:bg-gray-700 shadow-sm font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
            Échanges
        </a>
        <a href="{{ route('admin.messages', ['filter' => 'all']) }}"
           class="px-4 py-2 text-sm rounded-md transition
                  {{ $filter === 'all' ? 'bg-white dark:bg-gray-700 shadow-sm font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100' }}">
            Tous
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    @if($filter === 'all')
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Type</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Expéditeur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">Contexte</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Contenu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($messages as $message)
                @php $isLoop = $filter === 'chatloop' || (($message->message_type ?? null) === 'chatloop'); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                        {{ $message->created_at->format('d/m/Y H:i') }}
                    </td>
                    @if($filter === 'all')
                    <td class="px-4 py-3">
                        @if($isLoop)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">ChatLoop</span>
                        @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">Échange</span>
                        @endif
                    </td>
                    @endif
                    <td class="px-4 py-3">
                        @if($message->sender)
                        <p class="font-medium text-gray-900 dark:text-gray-100 text-xs">{{ $message->sender->name }}</p>
                        <p class="text-xs text-gray-500 truncate max-w-[120px]">{{ $message->sender->email }}</p>
                        @else
                        <span class="text-xs text-gray-400 italic">Système</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 hidden sm:table-cell">
                        @if($isLoop && isset($message->loop))
                            <span class="font-medium">{{ $message->loop->name }}</span>
                        @elseif(isset($message->transaction))
                            <span>{{ $message->transaction->buyer->name ?? '?' }}</span>
                            <span class="text-gray-400"> ↔ </span>
                            <span>{{ $message->transaction->seller->name ?? '?' }}</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-xs">
                        <p class="truncate">{{ Str::limit($message->body, 100) }}</p>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $filter === 'all' ? 5 : 4 }}" class="px-4 py-12 text-center">
                        @if($filter === 'chatloop')
                        <p class="text-sm text-gray-500 dark:text-gray-400">Aucun message ChatLoop</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Les messages de vos boucles apparaîtront ici.</p>
                        @elseif($filter === 'exchanges')
                        <p class="text-sm text-gray-500 dark:text-gray-400">Aucun échange</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Les messages de vos transactions apparaîtront ici.</p>
                        @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Aucun message dans votre Organisation.</p>
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($messages->hasPages())
    <div class="mt-4">{{ $messages->links() }}</div>
    @endif
</x-admin-layout>