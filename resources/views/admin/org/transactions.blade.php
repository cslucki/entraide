<x-org-admin-layout :title="__('navigation.org_admin_transactions')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_transactions') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_transactions') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_user') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('navigation.org_admin_all_statuses') }}</option>
            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('navigation.org_admin_tx_pending') }}</option>
            <option value="accepted" {{ request('status') === 'accepted' ? 'selected' : '' }}>{{ __('navigation.org_admin_tx_accepted') }}</option>
            <option value="buyer_done" {{ request('status') === 'buyer_done' ? 'selected' : '' }}>{{ __('navigation.org_admin_tx_buyer_done') }}</option>
            <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>{{ __('navigation.org_admin_tx_completed') }}</option>
            <option value="refused" {{ request('status') === 'refused' ? 'selected' : '' }}>{{ __('navigation.org_admin_tx_refused') }}</option>
            <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>{{ __('navigation.org_admin_tx_cancelled') }}</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('organization.admin.transactions', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_subject') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_buyer') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_seller') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_points') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_date') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($transactions as $tx)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100 max-w-xs truncate">{{ $tx->subject }}</p>
                        <p class="text-xs text-gray-500 font-mono">{{ substr($tx->id, 0, 8) }}…</p>
                    </td>
                    <td class="px-4 py-3">
                        @if($tx->buyer)
                        <a href="{{ route('profile.show', $tx->buyer) }}" class="text-indigo-600 hover:underline text-xs">{{ $tx->buyer->full_name }}</a>
                        @else <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($tx->seller)
                        <a href="{{ route('profile.show', $tx->seller) }}" class="text-indigo-600 hover:underline text-xs">{{ $tx->seller->full_name }}</a>
                        @else <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ $tx->points_agreed ?? $tx->points_proposed }}</span>
                        @if($tx->points_agreed && $tx->points_agreed !== $tx->points_proposed)
                        <span class="text-xs text-gray-400 line-through ml-1">{{ $tx->points_proposed }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $colors = [
                                'pending'    => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
                                'accepted'   => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
                                'buyer_done' => 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
                                'completed'  => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                'refused'    => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
                                'cancelled'  => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
                            ];
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $colors[$tx->status] ?? '' }}">{{ $tx->status_label }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_transactions') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($transactions->hasPages())
    <div class="mt-4">{{ $transactions->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>