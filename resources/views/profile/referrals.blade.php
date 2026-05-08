<x-app-layout>
    <div class="py-12 px-4">
        <div class="max-w-4xl mx-auto">
            {{-- Header with airy spacing --}}
            <div class="mb-12 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">Mes parrainages</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Suivez vos filleuls et vos récompenses gagnées.</p>
                </div>
                <a href="{{ route('profile.show', $user) }}" class="inline-flex items-center text-xs font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-widest hover:text-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                    Retour au profil
                </a>
            </div>

            {{-- Referral Code Hero Card - Gemini Aesthetic --}}
            @php
                $regBonus = (int) \App\Models\Setting::get('referral_reward_registration', 50);
                $transBonus = (int) \App\Models\Setting::get('referral_reward_first_transaction', 100);
            @endphp
            <div class="bg-indigo-600 rounded-[2.5rem] p-10 text-white shadow-2xl shadow-indigo-500/20 relative overflow-hidden mb-12">
                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-8">
                    <div class="max-w-md">
                        <h2 class="text-2xl font-bold tracking-tight">Partagez l'expérience Entraide</h2>
                        <p class="text-indigo-100/80 mt-3 leading-relaxed">Invitez vos collègues et recevez <span class="font-bold text-white">{{ $regBonus }} pts</span> dès leur inscription, plus <span class="font-bold text-white">{{ $transBonus }} pts</span> après leur premier échange.</p>
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
                        class="bg-white/10 backdrop-blur-md p-6 rounded-[2rem] border border-white/20 cursor-pointer hover:bg-white/20 transition-all duration-300 relative group flex items-center gap-6"
                        title="Cliquer pour copier">
                        <div class="space-y-1">
                            <p class="text-[10px] font-bold uppercase tracking-widest opacity-60">Votre code unique</p>
                            <code class="text-3xl font-mono font-bold tracking-tighter">{{ $user->referral_code }}</code>
                        </div>
                        <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg x-show="!copied" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                            <svg x-show="copied" class="w-5 h-5 text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </div>

                        <div class="absolute -top-12 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-[10px] font-bold px-3 py-1.5 rounded-full shadow-xl transition-all opacity-0 group-hover:opacity-100 whitespace-nowrap pointer-events-none" :class="{ 'opacity-100 bg-emerald-500': copied }">
                            <span x-show="!copied">CLIQUEZ POUR COPIER</span>
                            <span x-show="copied">CODE COPIÉ !</span>
                        </div>
                    </div>
                </div>
                {{-- Decorative background elements --}}
                <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-white/5 rounded-full blur-3xl"></div>
                <div class="absolute -left-10 -top-10 w-40 h-40 bg-indigo-400/20 rounded-full blur-3xl"></div>
            </div>

            {{-- Stats Grid --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-12">
                <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl p-8 rounded-[2rem] border border-gray-200 dark:border-gray-700/50 shadow-sm transition-all hover:shadow-md">
                    <p class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-2">Total Filleuls</p>
                    <p class="text-4xl font-bold text-gray-900 dark:text-white tracking-tight">{{ $referrals->count() }}</p>
                </div>
                <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl p-8 rounded-[2rem] border border-gray-200 dark:border-gray-700/50 shadow-sm transition-all hover:shadow-md">
                    <p class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-2">Échanges réalisés</p>
                    <p class="text-4xl font-bold text-emerald-500 tracking-tight">{{ $referrals->where('first_transaction_reward_paid', true)->count() }}</p>
                </div>
                <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl p-8 rounded-[2rem] border border-gray-200 dark:border-gray-700/50 shadow-sm transition-all hover:shadow-md">
                    <p class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-2">Points gagnés</p>
                    <p class="text-4xl font-bold text-indigo-500 tracking-tight">
                        @php
                            $total = ($referrals->count() * $regBonus) + ($referrals->where('first_transaction_reward_paid', true)->count() * $transBonus);
                        @endphp
                        {{ number_format($total) }}
                    </p>
                </div>
            </div>

            {{-- Referral List Card --}}
            <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl rounded-[2.5rem] border border-gray-200 dark:border-gray-700/50 shadow-sm overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-100 dark:border-gray-700/50">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Vos recrues</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest border-b border-gray-50 dark:border-gray-800/50">
                                <th class="px-8 py-4">Utilisateur</th>
                                <th class="px-8 py-4">Rejoint le</th>
                                <th class="px-8 py-4 text-center">Bonus Inscr.</th>
                                <th class="px-8 py-4 text-right">Bonus 1ère Tx</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 dark:divide-gray-800/50">
                            @forelse($referrals as $referral)
                            <tr class="group hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-4">
                                        <img src="{{ $referral->referee->avatar_url }}" class="w-10 h-10 rounded-2xl object-cover" alt="">
                                        <span class="text-sm font-bold text-gray-900 dark:text-white tracking-tight">{{ $referral->referee->name }}</span>
                                    </div>
                                </td>
                                <td class="px-8 py-5 text-sm text-gray-500 dark:text-gray-400 tabular-nums">
                                    {{ $referral->created_at->format('d M Y') }}
                                </td>
                                <td class="px-8 py-5 text-center">
                                    <span class="text-xs font-bold text-emerald-500">+{{ $regBonus }}</span>
                                </td>
                                <td class="px-8 py-5 text-right">
                                    @if($referral->first_transaction_reward_paid)
                                        <span class="text-xs font-bold text-emerald-500">+{{ $transBonus }}</span>
                                    @else
                                        <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500 uppercase tracking-widest">En attente</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="px-8 py-20 text-center">
                                    <div class="w-16 h-16 bg-gray-50 dark:bg-gray-800/50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                                        <svg class="w-8 h-8 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                    </div>
                                    <p class="text-sm text-gray-400 dark:text-gray-500 italic">Aucun filleul pour le moment. Commencez à partager !</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
