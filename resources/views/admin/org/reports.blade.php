<x-org-admin-layout :title="__('navigation.org_admin_reports')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_reports') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_reports') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_report') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('navigation.org_admin_all_statuses') }}</option>
            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>{{ __('navigation.org_admin_report_status_pending') }}</option>
            <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>{{ __('navigation.org_admin_report_status_resolved') }}</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('organization.admin.reports', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_reason') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_reporter') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_page') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_date') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($bugReports as $report)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $report->status === 'resolved' ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $report->reason }}</p>
                        @if($report->details)
                        <p class="text-xs text-gray-500 mt-0.5 truncate max-w-xs">{{ $report->details }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($report->reporter)
                        <a href="{{ route('profile.show', $report->reporter) }}" class="text-indigo-600 hover:underline text-xs">{{ $report->reporter->full_name }}</a>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 truncate max-w-[200px]">{{ $report->page_url ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @php
                            $sc = $report->status === 'resolved'
                                ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
                                : 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300';
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc }}">{{ __("navigation.org_admin_report_status_{$report->status}") }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $report->created_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($report->status === 'pending')
                        <form method="POST" action="{{ route('organization.admin.reports.resolve', [$organization, $report]) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" onclick="return confirm('{{ __('navigation.org_admin_confirm_resolve') }}')"
                                class="px-2 py-1 text-xs rounded border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300 hover:bg-green-50 dark:hover:bg-green-900/30">
                                {{ __('navigation.org_admin_resolve_report') }}
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_reports') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($bugReports->hasPages())
    <div class="mt-4">{{ $bugReports->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
