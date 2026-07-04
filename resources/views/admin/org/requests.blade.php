<x-org-admin-layout :title="__('navigation.org_admin_requests')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_requests') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_requests') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_request') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('navigation.org_admin_all_statuses') }}</option>
            <option value="open" {{ request('status') === 'open' ? 'selected' : '' }}>{{ __('navigation.org_admin_status_open') }}</option>
            <option value="in_progress" {{ request('status') === 'in_progress' ? 'selected' : '' }}>{{ __('navigation.org_admin_status_in_progress') }}</option>
            <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>{{ __('navigation.org_admin_status_closed') }}</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('organization.admin.requests', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_request') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_author') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_category') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_budget') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($requests as $req)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <a href="{{ route('requests.show', $req) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $req->title }}</a>
                        @if($req->deadline)
                        <p class="text-xs text-gray-400">{{ __('navigation.org_admin_before') }} {{ $req->deadline->format('d/m/Y') }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($req->user)
                        <a href="{{ route('profile.show', $req->user) }}" class="text-indigo-600 hover:underline text-xs">{{ $req->user->fullName }}</a>
                        @else <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($req->category)
                        <span class="px-2 py-0.5 rounded-full text-xs text-white" style="background-color:{{ $req->category->color }}">
                            {{ $req->category->displayName('transactions') }}
                        </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 text-xs">
                        {{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $sc = ['open' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                   'in_progress' => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
                                   'closed' => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400'];
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc[$req->status] ?? '' }}">{{ __("navigation.org_admin_request_status_{$req->status}") }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center">
                            @if($req->status !== 'closed')
                            <form method="POST" action="{{ route('organization.admin.requests.close', ['organization' => $organization, 'serviceRequest' => $req]) }}"
                                  onsubmit="return confirm('{{ __('navigation.org_admin_confirm_close') }}')">
                                @csrf @method('PATCH')
                                <button class="text-xs text-orange-500 hover:underline">{{ __('navigation.org_admin_close') }}</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_requests') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($requests->hasPages())
    <div class="mt-4">{{ $requests->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>