<x-app-layout>
    <x-slot name="title">Mes points</x-slot>

    <x-page-container>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-2">Historique des points</h1>
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

        <!-- Graphique -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 mb-8">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Évolution du solde</h2>
            <div class="h-64">
                <canvas id="pointsChart"></canvas>
            </div>
        </div>

        @if($referralLink)
        <div id="invitations" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-8">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Invitations</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Chaque membre que vous invitez et qui rejoint la boucle vous rapporte des points.
            </p>
            <div class="flex gap-6 text-sm text-gray-600 dark:text-gray-400 mb-4">
                <div>
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $sentReferralsCount }}</span>
                    <span class="ml-1">invitation(s)</span>
                </div>
                <div>
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $activatedReferralsCount }}</span>
                    <span class="ml-1">activation(s)</span>
                </div>
                <div>
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $referralPointsEarned }}</span>
                    <span class="ml-1">pts gagnés</span>
                </div>
            </div>
            <div class="flex gap-2" x-data="{ copied: false, link: @js($referralLink) }">
                <input type="text" readonly value="{{ $referralLink }}" data-referral-link-points
                       class="flex-1 max-w-xs px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 select-all">
                <button type="button" @click="
                    const input = $root.querySelector('[data-referral-link-points]');
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(link);
                    } else if (input) {
                        input.select();
                        document.execCommand('copy');
                    }
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                " class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                    <span x-show="!copied">Copier</span>
                    <span x-show="copied">Copié !</span>
                </button>
                <a href="https://wa.me/?text={{ urlencode($referralLink) }}" target="_blank" rel="noopener noreferrer"
                   class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition whitespace-nowrap">
                    WhatsApp
                </a>
            </div>
        </div>
        @endif

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
                                'referral_reward' => 'Récompense invitation',
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

    @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('pointsChart').getContext('2d');
            const isDark = document.documentElement.classList.contains('dark');

            const textColor = isDark ? '#9ca3af' : '#6b7280';
            const gridColor = isDark ? 'rgba(75, 85, 99, 0.2)' : 'rgba(209, 213, 219, 0.3)';
            const primaryColor = '#6366f1';

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: {!! json_encode($labels) !!},
                    datasets: [{
                        label: 'Points',
                        data: {!! json_encode($history) !!},
                        borderColor: primaryColor,
                        backgroundColor: isDark ? 'rgba(99, 102, 241, 0.1)' : 'rgba(99, 102, 241, 0.05)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: primaryColor,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: textColor,
                                font: {
                                    size: 10
                                },
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 8
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: gridColor
                            },
                            ticks: {
                                color: textColor,
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
    @endpush
    </x-page-container>
</x-app-layout>
