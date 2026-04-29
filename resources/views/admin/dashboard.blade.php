<x-app-layout title="Admin — Tableau de bord">
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Tableau de bord admin</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Vue d'ensemble de la plateforme</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.users') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Utilisateurs</a>
                <a href="{{ route('admin.reports') }}" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">
                    Signalements
                    @if($stats['reports'] > 0)
                    <span class="ml-1 bg-white text-red-600 rounded-full px-1.5 text-xs font-bold">{{ $stats['reports'] }}</span>
                    @endif
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $stats['users'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Utilisateurs</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $stats['services'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Services actifs</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $stats['transactions'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Transactions</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['completed'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Complétées</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($stats['points']) }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Points en circ.</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-red-500 dark:text-red-400">{{ $stats['reports'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Signalements</p>
            </div>
        </div>

        <div class="grid md:grid-cols-2 gap-6">
            <!-- Derniers utilisateurs -->
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
                        <span class="text-xs text-gray-400">{{ $u->created_at->diffForHumans() }}</span>
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
    </div>
</x-app-layout>
