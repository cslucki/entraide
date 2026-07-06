<x-app-layout>
    @php
        $_blogRoute = function ($name, $parameters = []) {
            $orgSlug = request()->route('organization');
            if (! $orgSlug || ! Route::has('organization.blog.'.$name)) {
                return route('blog.'.$name, $parameters);
            }
            return route('organization.blog.'.$name, array_merge(['organization' => $orgSlug], $parameters));
        };
    @endphp
    <x-slot name="title">{{ __('blog.title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="hidden sm:block text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('blog.my_posts') }}</h1>
                <p class="mt-1 text-sm sm:text-base text-gray-500 dark:text-gray-400">{{ __('blog.my_posts_subtitle') }}</p>
            </div>
            <a href="{{ $_blogRoute('create') }}" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('blog.btn_new_post') }}
            </a>
        </div>

        <div x-data="{ tab: 'drafts' }">
        <div class="flex gap-1 mb-6 border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
            <button @click="tab = 'drafts'" :class="tab === 'drafts' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px shrink-0">
                {{ __('blog.tab_drafts') }} ({{ $drafts->total() }})
            </button>
            <button @click="tab = 'published'" :class="tab === 'published' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px shrink-0">
                {{ __('blog.tab_published') }} ({{ $publishedPosts->total() }})
            </button>
            <button @click="tab = 'coauthored'" :class="tab === 'coauthored' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px shrink-0">
                {{ __('blog.tab_coauthors') }} ({{ $coAuthoredPosts->total() }})
            </button>
            <button @click="tab = 'comments'" :class="tab === 'comments' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px shrink-0">
                {{ __('blog.tab_comments') }} ({{ $comments->total() }})
            </button>
        </div>

        @php
            $cols = '';

            $isAdmin = auth()->user()?->is_admin ?? false;

            $responsibleColor = 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20';
            $coauthorColor = 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20';

            $maxVisibleCoAuthors = 3;
        @endphp

        {{-- DRAFTS --}}
        <div x-show="tab === 'drafts'">
            @if($drafts->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <p>{{ __('blog.empty_drafts') }}</p>
                <a href="{{ $_blogRoute('create') }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.create_first') }}</a>
            </div>
            @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.table_title') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">{{ __('blog.table_status') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">{{ __('blog.table_role') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">{{ __('blog.table_coauthors') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden xl:table-cell">{{ __('blog.table_last_modified') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($drafts as $post)
                        @php $role = $post->user_id === $user->id ? 'responsible' : 'coauthor'; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-5 py-4">
                                <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                                    {{ $post->title }}
                                </a>
                            </td>
                            <td class="px-5 py-4 hidden sm:table-cell">
                                @php $colors = ['draft' => 'text-gray-500 bg-gray-100 dark:bg-gray-700', 'pending' => 'text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20']; @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$post->status] ?? '' }}">
                                    {{ ['draft' => __('blog.status_draft'), 'pending' => __('blog.status_pending')][$post->status] ?? $post->status }}
                                </span>
                            </td>
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $role === 'responsible' ? $responsibleColor : $coauthorColor }}">
                                    {{ $role === 'responsible' ? __('blog.role_responsible') : __('blog.role_coauthor') }}
                                </span>
                            </td>
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <div class="flex items-center gap-1.5">
                                    @php $coAuthors = $post->coAuthors ?? collect(); $visible = $coAuthors->take($maxVisibleCoAuthors); $remaining = $coAuthors->count() - $maxVisibleCoAuthors; @endphp
                                    @if($coAuthors->isNotEmpty())
                                        <div class="flex -space-x-2">
                                            @foreach($visible as $ca)
                                            <div class="group relative">
                                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-medium ring-2 ring-white dark:ring-gray-800 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 cursor-default">{{ $ca->initials }}</span>
                                                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded-md bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-[11px] font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-150 z-50 shadow-lg">{{ $ca->fullName }}</div>
                                            </div>
                                            @endforeach
                                        </div>
                                        @if($remaining > 0)
                                            <span class="text-xs text-gray-400">+{{ $remaining }}</span>
                                        @endif
                                        <span class="hidden lg:inline text-xs text-gray-500 dark:text-gray-400 truncate max-w-[120px] ml-1">{{ $visible->pluck('fullName')->implode(', ') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">&mdash;</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4 text-gray-400 hidden xl:table-cell text-xs">{{ $post->updated_at->diffForHumans() }}</td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ $_blogRoute('edit', ['post' => $post]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.btn_edit') }}</a>
                                    @can('delete', $post)
                                    <form action="{{ $_blogRoute('destroy', ['post' => $post]) }}" method="POST"
                                          onsubmit="return confirm('{{ __('blog.confirm_delete_post') }}')" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:underline">{{ __('blog.btn_delete_post') }}</button>
                                    </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $drafts->links() }}</div>
            @endif
        </div>

        {{-- PUBLISHED --}}
        <div x-show="tab === 'published'">
            @if($publishedPosts->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                <p>{{ __('blog.empty_published') }}</p>
                <a href="{{ $_blogRoute('create') }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.create_first') }}</a>
            </div>
            @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.table_title') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">{{ __('blog.table_role') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">{{ __('blog.table_coauthors') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">{{ __('blog.table_views') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">{{ __('blog.table_likes') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden xl:table-cell">{{ __('blog.table_last_modified') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($publishedPosts as $post)
                        @php $role = $post->user_id === $user->id ? 'responsible' : 'coauthor'; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-5 py-4">
                                <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                                    {{ $post->title }}
                                </a>
                            </td>
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $role === 'responsible' ? $responsibleColor : $coauthorColor }}">
                                    {{ $role === 'responsible' ? __('blog.role_responsible') : __('blog.role_coauthor') }}
                                </span>
                            </td>
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <div class="flex items-center gap-1.5">
                                    @php $coAuthors = $post->coAuthors ?? collect(); $visible = $coAuthors->take($maxVisibleCoAuthors); $remaining = $coAuthors->count() - $maxVisibleCoAuthors; @endphp
                                    @if($coAuthors->isNotEmpty())
                                        <div class="flex -space-x-2">
                                            @foreach($visible as $ca)
                                            <div class="group relative">
                                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-medium ring-2 ring-white dark:ring-gray-800 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 cursor-default">{{ $ca->initials }}</span>
                                                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded-md bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-[11px] font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-150 z-50 shadow-lg">{{ $ca->fullName }}</div>
                                            </div>
                                            @endforeach
                                        </div>
                                        @if($remaining > 0)
                                            <span class="text-xs text-gray-400">+{{ $remaining }}</span>
                                        @endif
                                        <span class="hidden lg:inline text-xs text-gray-500 dark:text-gray-400 truncate max-w-[120px] ml-1">{{ $visible->pluck('fullName')->implode(', ') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">&mdash;</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ number_format($post->views_count) }}</td>
                            <td class="px-5 py-4 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ $post->likes_count }}</td>
                            <td class="px-5 py-4 text-gray-400 hidden xl:table-cell text-xs">{{ $post->updated_at->diffForHumans() }}</td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ $_blogRoute('edit', ['post' => $post]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.btn_edit') }}</a>
                                    @can('delete', $post)
                                    <form action="{{ $_blogRoute('destroy', ['post' => $post]) }}" method="POST"
                                          onsubmit="return confirm('{{ __('blog.confirm_delete_post') }}')" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:underline">{{ __('blog.btn_delete_post') }}</button>
                                    </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $publishedPosts->links() }}</div>
            @endif
        </div>

        {{-- CO-AUTHORS --}}
        <div x-show="tab === 'coauthored'">
            @if($coAuthoredPosts->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/></svg>
                <p>{{ __('blog.empty_coauthored') }}</p>
            </div>
            @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.table_title') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">{{ __('blog.table_status') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">{{ __('blog.table_responsible') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">{{ __('blog.table_coauthors') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden xl:table-cell">{{ __('blog.table_last_modified') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($coAuthoredPosts as $post)
                        @php $role = $post->user_id === $user->id ? 'responsible' : 'coauthor'; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-5 py-4">
                                <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                                    {{ $post->title }}
                                </a>
                            </td>
                            <td class="px-5 py-4 hidden sm:table-cell">
                                @php
                                    $statusColors = ['draft' => 'text-gray-500 bg-gray-100 dark:bg-gray-700', 'pending' => 'text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20', 'published' => 'text-green-700 bg-green-50 dark:bg-green-900/20'];
                                    $statusLabels = ['draft' => __('blog.status_draft'), 'pending' => __('blog.status_pending'), 'published' => __('blog.status_published')];
                                @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$post->status] ?? '' }}">
                                    {{ $statusLabels[$post->status] ?? $post->status }}
                                </span>
                            </td>
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <div class="flex items-center gap-1.5">
                                    <div class="group relative">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-medium ring-2 ring-white dark:ring-gray-800 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 cursor-default">{{ $post->user?->initials ?? '?' }}</span>
                                        <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded-md bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-[11px] font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-150 z-50 shadow-lg">{{ $post->user?->fullName ?? '—' }}</div>
                                    </div>
                                    <span class="text-xs text-gray-600 dark:text-gray-400 truncate max-w-[100px]">{{ $post->user?->fullName ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-4 hidden lg:table-cell">
                                <div class="flex items-center gap-1.5">
                                    @php $coAuthors = $post->coAuthors ?? collect(); $visible = $coAuthors->take($maxVisibleCoAuthors); $remaining = $coAuthors->count() - $maxVisibleCoAuthors; @endphp
                                    @if($coAuthors->isNotEmpty())
                                        <div class="flex -space-x-2">
                                            @foreach($visible as $ca)
                                            <div class="group relative">
                                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-medium ring-2 ring-white dark:ring-gray-800 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 cursor-default">{{ $ca->initials }}</span>
                                                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded-md bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-[11px] font-medium whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity duration-150 z-50 shadow-lg">{{ $ca->fullName }}</div>
                                            </div>
                                            @endforeach
                                        </div>
                                        @if($remaining > 0)
                                            <span class="text-xs text-gray-400">+{{ $remaining }}</span>
                                        @endif
                                        <span class="hidden lg:inline text-xs text-gray-500 dark:text-gray-400 truncate max-w-[120px] ml-1">{{ $visible->pluck('fullName')->implode(', ') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">&mdash;</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4 text-gray-400 hidden xl:table-cell text-xs">{{ $post->updated_at->diffForHumans() }}</td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ $_blogRoute('edit', ['post' => $post]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.btn_edit') }}</a>
                                    <a href="{{ $_blogRoute('edit', ['post' => $post]) }}" class="text-xs text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline">{{ __('blog.action_manage_coauthors') }}</a>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $coAuthoredPosts->links() }}</div>
            @endif
        </div>

        {{-- COMMENTS --}}
        <div x-show="tab === 'comments'">
            @if($comments->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p>{{ __('blog.empty_comments') }}</p>
            </div>
            @else
            <div class="space-y-3">
                @foreach($comments as $comment)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-800 dark:text-gray-200">{{ Str::limit($comment->content, 200) }}</p>
                            <p class="mt-2 text-xs text-gray-400">
                                {{ __('blog.on') }} <a href="{{ $_blogRoute('show', ['post' => $comment->post]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $comment->post->title }}</a>
                                · {{ $comment->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-4">{{ $comments->links() }}</div>
            @endif
        </div>
    </div>
    </x-page-container>
</x-app-layout>