<x-org-admin-layout :title="__('dashboard.org_admin_dashboard', ['organization' => $organization->name])" :organization="$organization">
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ __('dashboard.org_admin_header', ['organization' => $organization->name]) }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.org_admin_subtitle') }}</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        @php
            $statCards = [
                ['label' => __('dashboard.org_admin_stat_users'), 'value' => $stats['users'], 'color' => 'indigo'],
                ['label' => __('dashboard.org_admin_stat_loops'), 'value' => $stats['loops'], 'color' => 'blue'],
                ['label' => __('dashboard.org_admin_stat_services'), 'value' => $stats['services'], 'color' => 'green'],
                ['label' => __('dashboard.org_admin_stat_requests'), 'value' => $stats['requests'], 'color' => 'orange'],
            ];
            $colorMap = [
                'indigo' => 'text-indigo-600 dark:text-indigo-400',
                'blue'   => 'text-blue-600 dark:text-blue-400',
                'green'  => 'text-green-600 dark:text-green-400',
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

    <!-- Recent users -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.org_admin_recent_users') }}</h2>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @forelse($recentUsers as $u)
            <div class="px-5 py-3 flex items-center gap-3">
                <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $u->full_name }}</p>
                    <p class="text-xs text-gray-500">{{ $u->email }} · {{ $u->points_balance }} pts</p>
                </div>
                @if($u->banned_at)
                <span class="text-xs bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300 px-2 py-0.5 rounded">{{ __('dashboard.org_admin_banned') }}</span>
                @endif
                <span class="text-xs text-gray-400 flex-shrink-0">{{ $u->created_at->diffForHumans() }}</span>
            </div>
            @empty
            <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.org_admin_no_users') }}</p>
            @endforelse
        </div>
    </div>
</x-org-admin-layout>
