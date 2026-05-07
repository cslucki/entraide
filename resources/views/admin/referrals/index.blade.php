<x-admin-layout>
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Supervision du Parrainage</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Suivez les invitations et les récompenses distribuées.</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Parrainages</p>
            <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mt-2">{{ $stats['total_referrals'] }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Bonus Inscription</p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ $stats['paid_registrations'] }}</p>
            <p class="text-xs text-gray-400 mt-1">Récompenses payées</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Double Parrainage</p>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ $stats['paid_first_tx'] }}</p>
            <p class="text-xs text-gray-400 mt-1">1ères transactions validées</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-8">
        <!-- Liste des parrainages -->
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Journal des parrainages</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 font-medium uppercase text-xs">
                        <tr>
                            <th class="px-6 py-3">Parrain</th>
                            <th class="px-6 py-3">Filleul</th>
                            <th class="px-6 py-3">Date</th>
                            <th class="px-6 py-3">Inscr.</th>
                            <th class="px-6 py-3">1ère TX</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($referrals as $referral)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $referral->referrer->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                                    <a href="{{ route('admin.users.edit', $referral->referrer) }}" class="text-indigo-600 hover:underline">{{ $referral->referrer->name }}</a>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $referral->referee->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                                    <a href="{{ route('admin.users.edit', $referral->referee) }}" class="text-indigo-600 hover:underline">{{ $referral->referee->name }}</a>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-500">{{ $referral->created_at->format('d/m/Y') }}</td>
                            <td class="px-6 py-4">
                                @if($referral->registration_reward_paid)
                                <span class="text-green-500">✅</span>
                                @else
                                <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @if($referral->first_transaction_reward_paid)
                                <span class="text-green-500">✅</span>
                                @else
                                <span class="text-gray-300">⏳</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-700">
                {{ $referrals->links() }}
            </div>
        </div>

        <!-- Top Parrains -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden h-fit">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Top Parrains</h2>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($stats['top_referrers'] as $user)
                <div class="px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <img src="{{ $user->avatar_url }}" class="w-8 h-8 rounded-full" alt="">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</p>
                            <p class="text-xs text-gray-500">{{ $user->email }}</p>
                        </div>
                    </div>
                    <span class="px-2 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 text-xs font-bold rounded-lg">
                        {{ $user->referrals_count }} filleuls
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</x-admin-layout>
