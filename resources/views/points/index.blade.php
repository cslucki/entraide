<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">Historique des points</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Toutes vos transactions de points, du plus récent au plus ancien.</p>

        <!-- Résumé -->
        <div class="grid grid-cols-3 gap-4 mb-8">
            <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-xl p-4 text-center border border-indigo-100 dark:border-indigo-800">
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ auth()->user()->points_balance }}</p>
                <p class="text-xs text-gray-500 mt-1">Solde actuel</p>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl p-4 text-center border border-green-100 dark:border-green-800">
                <p class="text-2xl font-bold text-green-600 dark:text-green-400">+{{ $earned }}</p>
                <p class="text-xs text-gray-500 mt-1">Total gagné</p>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-xl p-4 text-center border border-red-100 dark:border-red-800">
                <p class="text-2xl font-bold text-red-500 dark:text-red-400">-{{ $spent }}</p>
                <p class="text-xs text-gray-500 mt-1">Total dépensé</p>
            </div>
        </div>

        <!-- Ledger -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            @forelse($entries as $entry)
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700 last:border-0">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0
                        {{ $entry->delta > 0 ? 'bg-green-100 dark:bg-green-900/30' : 'bg-red-100 dark:bg-red-900/30' }}">
                        <span class="text-base">{{ $entry->delta > 0 ? '↑' : '↓' }}</span>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ match($entry->reason) {
                                'welcome_bonus'   => 'Bonus de bienvenue',
                                'exchange_earned' => 'Échange — gain',
                                'exchange_spent'  => 'Échange — dépense',
                                'adjustment'      => 'Ajustement',
                                default           => $entry->reason,
                            } }}
                        </p>
                        @if($entry->transaction)
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $entry->transaction->subject }}
                        </p>
                        @endif
                    </div>
                </div>
                <div class="text-right">
                    <p class="font-bold {{ $entry->delta > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">
                        {{ $entry->delta > 0 ? '+' : '' }}{{ $entry->delta }} pts
                    </p>
                    <p class="text-xs text-gray-400">{{ $entry->created_at->format('d/m/Y H:i') }}</p>
                </div>
            </div>
            @empty
            <div class="px-5 py-12 text-center text-gray-400 text-sm">Aucun mouvement de points.</div>
            @endforelse
        </div>

        <div class="mt-6">{{ $entries->links() }}</div>
    </div>
</x-app-layout>
