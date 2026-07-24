<x-org-admin-layout :title="__('navigation.org_admin_messages')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_messages') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_messages') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_user') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->has('search'))
        <a href="{{ route('organization.admin.messages', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_sender') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_body') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_type') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_transaction') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_date') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($messages as $message)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        @if($message->sender)
                        <a href="{{ route('profile.show', $message->sender) }}" class="text-indigo-600 hover:underline text-xs">{{ $message->sender->full_name }}</a>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 max-w-xs truncate">{{ Str::limit($message->body, 80) }}</td>
                    <td class="px-4 py-3">
                        @if($message->isSystem())
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">system</span>
                        @else
                        <span class="text-xs text-gray-500">user</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        @if($message->transaction)
                        <a href="{{ route('organization.admin.transactions', [$organization, 'search' => $message->transaction_id]) }}" class="text-indigo-600 hover:underline">
                            #{{ substr($message->transaction_id, 0, 8) }}
                        </a>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $message->created_at->format('d/m/Y H:i') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_messages') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($messages->hasPages())
    <div class="mt-4">{{ $messages->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
