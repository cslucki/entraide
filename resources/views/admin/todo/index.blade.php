<x-admin-layout title="{{ __('blog.admin_todo_title') }}">
    <form method="GET" class="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-8">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('blog.admin_todo_search_placeholder') }}"
            class="xl:col-span-2 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">

        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>{{ __('blog.filter_all_organizations') }}</option>
            @foreach($organizations as $org)
                <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }} {{ $org->is_default ? __('blog.filter_default') : '' }}</option>
            @endforeach
        </select>

        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('blog.filter_all_statuses') }}</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ __('blog.todo_status_'.$status) }}</option>
            @endforeach
        </select>

        <select name="author_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('blog.admin_todo_all_authors') }}</option>
            @foreach($authors as $author)
                <option value="{{ $author->id }}" {{ request('author_id') === $author->id ? 'selected' : '' }}>{{ $author->full_name }}</option>
            @endforeach
        </select>

        <select name="assignee_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('blog.admin_todo_all_assignees') }}</option>
            <option value="unassigned" {{ request('assignee_id') === 'unassigned' ? 'selected' : '' }}>{{ __('blog.todo_unassigned') }}</option>
            @foreach($assignees as $assignee)
                <option value="{{ $assignee->id }}" {{ request('assignee_id') === $assignee->id ? 'selected' : '' }}>{{ $assignee->full_name }}</option>
            @endforeach
        </select>

        <select name="sort" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="updated_at" {{ $sort === 'updated_at' ? 'selected' : '' }}>{{ __('blog.admin_todo_sort_updated') }}</option>
            <option value="created_at" {{ $sort === 'created_at' ? 'selected' : '' }}>{{ __('blog.admin_todo_sort_created') }}</option>
            <option value="author" {{ $sort === 'author' ? 'selected' : '' }}>{{ __('blog.table_author') }}</option>
            <option value="assignee" {{ $sort === 'assignee' ? 'selected' : '' }}>{{ __('blog.admin_todo_assignee') }}</option>
        </select>

        <select name="direction" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="desc" {{ $direction === 'desc' ? 'selected' : '' }}>{{ __('blog.admin_todo_direction_desc') }}</option>
            <option value="asc" {{ $direction === 'asc' ? 'selected' : '' }}>{{ __('blog.admin_todo_direction_asc') }}</option>
        </select>

        <div class="flex gap-2 md:col-span-2 xl:col-span-8">
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('blog.btn_filter') }}</button>
            @if(request()->hasAny(['search', 'organization_id', 'status', 'author_id', 'assignee_id', 'sort', 'direction']))
                <a href="{{ route('admin.todo') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('blog.btn_clear') }}</a>
            @endif
        </div>
    </form>

    @if(session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg text-sm text-green-700 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 px-4 py-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg text-sm text-red-700 dark:text-red-300">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="hidden lg:block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.admin_todo_task') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.table_organization') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.admin_todo_people') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.table_status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('blog.table_date') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($todos as $todo)
                    @php
                        $post = $todo->blogPost;
                        $validAssignees = collect([$post?->user])->filter()->merge($post?->coAuthors ?? collect())->unique('id');
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 align-top">
                        <td class="px-4 py-3 min-w-72">
                            <form id="todo-update-{{ $todo->id }}" action="{{ route('admin.todo.update', $todo) }}" method="POST" class="space-y-2">
                                @csrf @method('PATCH')
                                <input name="title" value="{{ old('title', $todo->title) }}" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm">
                            </form>
                            @if($post)
                                <a href="{{ route('blog.show', $post) }}" target="_blank" class="mt-2 block text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ $post->title }}</a>
                            @endif
                            <span class="text-xs text-gray-400">{{ trans_choice('blog.admin_todo_thread_count', $todo->threads_count, ['count' => $todo->threads_count]) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $todo->organization?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 space-y-1">
                            <div>{{ __('blog.table_author') }}: {{ $post?->user?->full_name ?? '—' }}</div>
                            <div>{{ __('blog.admin_todo_creator') }}: {{ $todo->user?->full_name ?? '—' }}</div>
                            <div>
                                <label class="sr-only" for="assigned-{{ $todo->id }}">{{ __('blog.admin_todo_assignee') }}</label>
                                <select id="assigned-{{ $todo->id }}" name="assigned_to" form="todo-update-{{ $todo->id }}" class="mt-1 w-full px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs">
                                    <option value="">{{ __('blog.todo_unassigned') }}</option>
                                    @foreach($validAssignees as $assignee)
                                        <option value="{{ $assignee->id }}" {{ $todo->assigned_to === $assignee->id ? 'selected' : '' }}>{{ $assignee->full_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <label class="sr-only" for="status-{{ $todo->id }}">{{ __('blog.table_status') }}</label>
                            <select id="status-{{ $todo->id }}" name="status" form="todo-update-{{ $todo->id }}" class="px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs">
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" {{ $todo->status === $status ? 'selected' : '' }}>{{ __('blog.todo_status_'.$status) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                            <div>{{ __('blog.admin_todo_created_at') }}: {{ $todo->created_at->format('d/m/Y H:i') }}</div>
                            <div>{{ __('blog.admin_todo_updated_at') }}: {{ $todo->updated_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="submit" form="todo-update-{{ $todo->id }}" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline mr-3">{{ __('blog.btn_save') }}</button>
                            <form action="{{ route('admin.todo.destroy', $todo) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('blog.admin_todo_confirm_delete', ['title' => addslashes($todo->title)]) }}')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline">{{ __('blog.delete_post') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-400 dark:text-gray-500">{{ __('blog.admin_todo_empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="space-y-4 lg:hidden">
        @forelse($todos as $todo)
            @php
                $post = $todo->blogPost;
                $validAssignees = collect([$post?->user])->filter()->merge($post?->coAuthors ?? collect())->unique('id');
            @endphp
            <article class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <form id="todo-mobile-update-{{ $todo->id }}" action="{{ route('admin.todo.update', $todo) }}" method="POST" class="space-y-3">
                    @csrf @method('PATCH')
                    <input name="title" value="{{ old('title', $todo->title) }}" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <select name="status" class="px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs">
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" {{ $todo->status === $status ? 'selected' : '' }}>{{ __('blog.todo_status_'.$status) }}</option>
                            @endforeach
                        </select>
                        <select name="assigned_to" class="px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-xs">
                            <option value="">{{ __('blog.todo_unassigned') }}</option>
                            @foreach($validAssignees as $assignee)
                                <option value="{{ $assignee->id }}" {{ $todo->assigned_to === $assignee->id ? 'selected' : '' }}>{{ $assignee->full_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>
                <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                    @if($post)
                        <a href="{{ route('blog.show', $post) }}" target="_blank" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $post->title }}</a>
                    @endif
                    <div>{{ __('blog.table_organization') }}: {{ $todo->organization?->name ?? '—' }}</div>
                    <div>{{ __('blog.table_author') }}: {{ $post?->user?->full_name ?? '—' }}</div>
                    <div>{{ __('blog.admin_todo_creator') }}: {{ $todo->user?->full_name ?? '—' }}</div>
                    <div>{{ __('blog.admin_todo_assignee') }}: {{ $todo->assignedTo?->full_name ?? __('blog.todo_unassigned') }}</div>
                    <div>{{ $todo->updated_at->format('d/m/Y H:i') }}</div>
                </div>
                <div class="flex items-center justify-between">
                    <button type="submit" form="todo-mobile-update-{{ $todo->id }}" class="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.btn_save') }}</button>
                    <form action="{{ route('admin.todo.destroy', $todo) }}" method="POST" onsubmit="return confirm('{{ __('blog.admin_todo_confirm_delete', ['title' => addslashes($todo->title)]) }}')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 dark:text-red-400 hover:underline">{{ __('blog.delete_post') }}</button>
                    </form>
                </div>
            </article>
        @empty
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8 text-center text-gray-400 dark:text-gray-500">{{ __('blog.admin_todo_empty') }}</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $todos->links() }}</div>
</x-admin-layout>
