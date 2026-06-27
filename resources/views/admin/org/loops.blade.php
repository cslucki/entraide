<x-org-admin-layout :title="__('navigation.org_admin_loops')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_loops') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_loops') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_loop') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('navigation.org_admin_loops_all') }}</option>
            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>{{ __('navigation.org_admin_loops_active') }}</option>
            <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>{{ __('navigation.org_admin_loops_archived') }}</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('organization.admin.loops', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_name') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_type') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_visibility') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_creator') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_date') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($loops as $boucle)
                <tr class="{{ $boucle->isArchived() ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $boucle->name }}</p>
                        <p class="text-xs text-gray-500">{{ $boucle->slug }}</p>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 capitalize">{{ $boucle->type }}</td>
                    <td class="px-4 py-3">
                        @php
                            $sc = $boucle->isActive() ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
                                : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc }}">{{ __("navigation.org_admin_loops_label_{$boucle->status}") }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 capitalize">{{ $boucle->visibility }}</td>
                    <td class="px-4 py-3">
                        @if($boucle->creator)
                        <a href="{{ route('profile.show', $boucle->creator) }}" class="text-indigo-600 hover:underline text-xs">{{ $boucle->creator->name }}</a>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $boucle->created_at->format('d/m/Y') }}</td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('organization.admin.loops.toggle-active', [$organization, $boucle]) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" onclick="return confirm('{{ $boucle->isActive() ? __('navigation.org_admin_confirm_archive') : __('navigation.org_admin_confirm_reactivate') }}')"
                                class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700">
                                {{ $boucle->isActive() ? __('navigation.org_admin_archive_loop') : __('navigation.org_admin_reactivate_loop') }}
                            </button>
                        </form>
                    </td>
                </tr>
                @if($boucle->relationLoaded('activeMembers'))
                <tr class="bg-gray-50 dark:bg-gray-800/50">
                    <td colspan="7" class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach($boucle->activeMembers as $member)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600">
                                {{ $member->user?->name ?? '—' }}
                                @if($member->role !== 'owner')
                                <form method="POST" action="{{ route('organization.admin.loops.members.remove', [$organization, $boucle, $member]) }}" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" onclick="return confirm('{{ __('navigation.org_admin_confirm_remove_member') }}')" class="text-red-500 hover:text-red-700 ml-1">&times;</button>
                                </form>
                                @endif
                            </span>
                            @endforeach
                            <form method="POST" action="{{ route('organization.admin.loops.members.add', [$organization, $boucle]) }}" class="inline-flex items-center gap-1">
                                @csrf
                                <select name="user_id" class="text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-1 py-0.5">
                                    <option value="">{{ __('navigation.org_admin_add_member') }}…</option>
                                    @foreach($organization->users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-2 py-1 text-xs rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('navigation.org_admin_add') }}</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endif
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_loops') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($loops->hasPages())
    <div class="mt-4">{{ $loops->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
