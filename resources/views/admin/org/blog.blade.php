<x-org-admin-layout :title="__('navigation.org_admin_blog')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_blog') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_blog') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_post') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('navigation.org_admin_all_statuses') }}</option>
            <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>{{ __('navigation.org_admin_blog_status_draft') }}</option>
            <option value="published" {{ request('status') === 'published' ? 'selected' : '' }}>{{ __('navigation.org_admin_blog_status_published') }}</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->hasAny(['search', 'status']))
        <a href="{{ route('organization.admin.blog', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_title') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_author') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_category') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_published') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_views') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('navigation.org_admin_table_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($posts as $post)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $post->trashed() ? 'opacity-50' : '' }}">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $post->title }}</p>
                        @if($post->slug)
                        <p class="text-xs text-gray-500">{{ $post->slug }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($post->user)
                        <a href="{{ route('profile.show', $post->user) }}" class="text-indigo-600 hover:underline text-xs">{{ $post->user->name }}</a>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $sc = $post->status === 'published'
                                ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300'
                                : 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300';
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $sc }}">{{ __("navigation.org_admin_blog_status_{$post->status}") }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                        @if($post->category)
                        <span class="px-2 py-0.5 rounded-full text-xs text-white" style="background-color:{{ $post->category->color ?? '#6366f1' }}">
                            {{ $post->category->name }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $post->published_at ? $post->published_at->format('d/m/Y') : '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $post->views_count ?? 0 }}</td>
                    <td class="px-4 py-3">
                        @if($post->status === 'draft')
                        <form method="POST" action="{{ route('organization.admin.blog.publish', [$organization, $post]) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" onclick="return confirm('{{ __('navigation.org_admin_confirm_publish') }}')"
                                class="px-2 py-1 text-xs rounded border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300 hover:bg-green-50 dark:hover:bg-green-900/30">
                                {{ __('navigation.org_admin_publish_post') }}
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('navigation.org_admin_no_posts') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($posts->hasPages())
    <div class="mt-4">{{ $posts->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
