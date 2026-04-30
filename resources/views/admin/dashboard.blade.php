<x-admin-layout title="Tableau de bord">
    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
        @php
            $statCards = [
                ['label' => 'Utilisateurs', 'value' => $stats['users'], 'color' => 'indigo'],
                ['label' => 'Bannis', 'value' => $stats['banned'], 'color' => 'red'],
                ['label' => 'Services actifs', 'value' => $stats['services'], 'color' => 'green'],
                ['label' => 'Transactions', 'value' => $stats['transactions'], 'color' => 'blue'],
                ['label' => 'Complétées', 'value' => $stats['completed'], 'color' => 'emerald'],
                ['label' => 'Points en circ.', 'value' => number_format($stats['points']), 'color' => 'purple'],
                ['label' => 'Signalements', 'value' => $stats['reports'], 'color' => 'orange'],
            ];
            $colorMap = [
                'indigo' => 'text-indigo-600 dark:text-indigo-400',
                'red'    => 'text-red-500 dark:text-red-400',
                'green'  => 'text-green-600 dark:text-green-400',
                'blue'   => 'text-blue-600 dark:text-blue-400',
                'emerald'=> 'text-emerald-600 dark:text-emerald-400',
                'purple' => 'text-purple-600 dark:text-purple-400',
                'orange' => 'text-orange-500 dark:text-orange-400',
            ];
        @endphp
        @foreach($statCards as $card)
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
            <p class="text-2xl font-bold {{ $colorMap[$card['color']] }}">{{ $card['value'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $card['label'] }}</p>
        </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <!-- Derniers inscrits -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Derniers inscrits</h2>
                <a href="{{ route('admin.users') }}" class="text-xs text-indigo-600 hover:underline">Voir tout</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($recentUsers as $u)
                <div class="px-5 py-3 flex items-center gap-3">
                    <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $u->name }}</p>
                        <p class="text-xs text-gray-500">{{ $u->email }} · {{ $u->points_balance }} pts</p>
                    </div>
                    @if($u->banned_at)
                    <span class="text-xs bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300 px-2 py-0.5 rounded">Banni</span>
                    @endif
                    <span class="text-xs text-gray-400 flex-shrink-0">{{ $u->created_at->diffForHumans() }}</span>
                </div>
                @endforeach
            </div>
        </div>

        <!-- Signalements en attente -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Signalements récents</h2>
                <a href="{{ route('admin.reports') }}" class="text-xs text-indigo-600 hover:underline">Voir tout</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($pendingReports as $report)
                <div class="px-5 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-900 dark:text-gray-100">
                                <span class="font-medium">{{ $report->reporter->name }}</span>
                                · {{ $report->reason }}
                            </p>
                            <p class="text-xs text-gray-500 truncate">{{ $report->reportable_type === 'App\Models\Service' ? 'Service' : 'Utilisateur' }}</p>
                        </div>
                        <div class="flex gap-2 flex-shrink-0">
                            <form method="POST" action="{{ route('admin.reports.review', $report) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-green-600 hover:underline">Traité</button>
                            </form>
                            <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-gray-400 hover:text-red-500">Ignorer</button>
                            </form>
                        </div>
                    </div>
                </div>
                @empty
                <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun signalement en attente.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-admin-layout>
