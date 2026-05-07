<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Mes parrainages</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Suivez vos filleuls et vos récompenses gagnées.</p>
            </div>
            <a href="{{ route('profile.show', $user) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                &larr; Retour au profil
            </a>
        </div>

        <div class="grid sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Total Filleuls</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $referrals->count() }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Premieres transactions</p>
                <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $referrals->where('first_transaction_reward_paid', true)->count() }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Points gagnés</p>
                <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">
                    @php
                        $regBonus = (int) \App\Models\Setting::get('referral_reward_registration', 50);
                        $transBonus = (int) \App\Models\Setting::get('referral_reward_first_transaction', 100);
                        $total = ($referrals->count() * $regBonus) + ($referrals->where('first_transaction_reward_paid', true)->count() * $transBonus);
                    @endphp
                    {{ number_format($total) }} pts
                </p>
            </div>
        </div>

        <div class="bg-indigo-600 rounded-xl p-6 mb-8 text-white">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <h2 class="text-xl font-bold">Votre code de parrainage</h2>
                    <p class="text-indigo-100 mt-1">Partagez ce code avec vos amis. Ils recevront {{ $regBonus }} pts à l'inscription et vous aussi !</p>
                </div>
                <div x-data="{
                        copied: false,
                        copy() {
                            navigator.clipboard.writeText('{{ $user->referral_code }}');
                            this.copied = true;
                            setTimeout(() => this.copied = false, 2000);
                        }
                    }"
                    @click="copy"
                    class="bg-white/10 p-4 rounded-lg border border-white/20 cursor-pointer hover:bg-white/20 transition relative group"
                    title="Cliquer pour copier">
                    <code class="text-2xl font-mono font-bold">{{ $user->referral_code }}</code>
                    <div class="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-[10px] px-2 py-1 rounded shadow-lg transition opacity-0 group-hover:opacity-100 whitespace-nowrap pointer-events-none" :class="{ 'opacity-100 bg-green-600': copied }">
                        <span x-show="!copied">Cliquer pour copier</span>
                        <span x-show="copied">Copié !</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-bold text-gray-900 dark:text-gray-100">Liste de vos filleuls</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-700/50 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <th class="px-6 py-3">Utilisateur</th>
                            <th class="px-6 py-3">Inscription</th>
                            <th class="px-6 py-3 text-center">Bonus Inscr.</th>
                            <th class="px-6 py-3 text-center">Bonus 1ère Tx</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($referrals as $referral)
                        <tr class="text-sm">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $referral->referee->avatar_url }}" class="w-8 h-8 rounded-full" alt="">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $referral->referee->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-500 dark:text-gray-400">
                                {{ $referral->created_at->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="text-green-600 font-semibold">+{{ $regBonus }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if($referral->first_transaction_reward_paid)
                                    <span class="text-green-600 font-semibold">+{{ $transBonus }}</span>
                                @else
                                    <span class="text-gray-400 text-xs italic">En attente</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-gray-500">
                                Aucun filleul pour le moment.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
