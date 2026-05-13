<x-admin-layout title="Suivi des invitations">
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
        Suivez la dynamique d'invitation et les contributions associées.
    </p>

    <!-- KPIs -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $totalReferrals }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Invitations</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ $pendingReferrals }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">En attente</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $activatedReferrals }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Activations</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($distributedReferralPoints) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Points d'invitation</p>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <!-- Invitations récentes -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Invitations récentes</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Invitant</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Invitée</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($recentInvitations as $inv)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        <td class="px-4 py-3">
                            <span class="text-gray-900 dark:text-gray-100 text-xs">{{ $inv->referrer?->name ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-gray-900 dark:text-gray-100 text-xs">{{ $inv->referred?->name ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $statusColors = [
                                    'pending'   => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
                                    'activated' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                ];
                                $statusLabels = [
                                    'pending'   => 'En attente',
                                    'activated' => 'Activée',
                                ];
                            @endphp
                            <span class="px-2 py-0.5 rounded text-xs {{ $statusColors[$inv->status] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400' }}">
                                {{ $statusLabels[$inv->status] ?? $inv->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $inv->created_at->format('d/m/Y') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">Aucune invitation récente.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Activations récentes -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Activations récentes</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membre entré dans la boucle</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Invitée par</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date d'activation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($recentActivations as $act)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        <td class="px-4 py-3">
                            <span class="text-gray-900 dark:text-gray-100 text-xs">{{ $act->referred?->name ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-gray-900 dark:text-gray-100 text-xs">{{ $act->referrer?->name ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $act->activated_at?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-400">Aucune activation récente.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Contributions -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">Contributions</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Membres qui font entrer d'autres personnes dans la boucle.</p>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membre</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Invitations</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Activations</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($contributors as $c)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <span class="text-gray-900 dark:text-gray-100 text-xs font-medium">{{ $c->name }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $c->invitations_count }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $c->activations_count }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="px-4 py-8 text-center text-sm text-gray-400">Aucune contribution à afficher pour le moment.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
