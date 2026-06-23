<x-org-admin-layout :title="__('navigation.org_admin_invitations')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_invitations') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_invitations') }}</p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_referrer') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_referred') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_depth') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_activated') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($referrals as $ref)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        @if($ref->referrer)
                        <a href="{{ route('profile.show', $ref->referrer) }}" class="text-indigo-600 hover:underline text-xs font-medium">{{ $ref->referrer->name }}</a>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($ref->referredUser)
                        <a href="{{ route('profile.show', $ref->referredUser) }}" class="text-indigo-600 hover:underline text-xs font-medium">{{ $ref->referredUser->name }}</a>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $sc = match($ref->status) {
                                'completed', 'activated' => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                'pending' => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
                                default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc }}">{{ $ref->status ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $ref->depth ?? 0 }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $ref->activated_at?->format('d/m/Y') ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_invitations') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($referrals->hasPages())
    <div class="mt-4">{{ $referrals->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
