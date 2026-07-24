<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\BlogTodo;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminBlogTodoController extends Controller
{
    private const STATUSES = ['todo', 'in_progress', 'done'];

    public function index(Request $request): View
    {
        $sort = in_array($request->input('sort'), ['created_at', 'updated_at', 'author', 'assignee'], true)
            ? $request->input('sort')
            : 'updated_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $selectedOrganizationId = $request->input('organization_id', 'all');

        $query = BlogTodo::query()
            ->select('blog_todos.*')
            ->with([
                'organization:id,name,slug',
                'blogPost:id,organization_id,user_id,title,slug,status',
                'blogPost.user:id,first_name,name,email,organization_id',
                'blogPost.coAuthors:id,first_name,name,email,organization_id',
                'user:id,first_name,name,email,organization_id',
                'assignedTo:id,first_name,name,email,organization_id',
            ])
            ->withCount('threads');

        if ($selectedOrganizationId !== 'all') {
            $query->where('blog_todos.organization_id', $selectedOrganizationId);
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('blog_todos.title', 'like', '%'.$search.'%')
                    ->orWhereHas('blogPost', fn ($postQuery) => $postQuery->where('title', 'like', '%'.$search.'%'));
            });
        }

        if (in_array($request->input('status'), self::STATUSES, true)) {
            $query->where('blog_todos.status', $request->input('status'));
        }

        if ($request->filled('author_id')) {
            $query->whereHas('blogPost', fn ($postQuery) => $postQuery->where('user_id', $request->input('author_id')));
        }

        if ($request->input('assignee_id') === 'unassigned') {
            $query->whereNull('blog_todos.assigned_to');
        } elseif ($request->filled('assignee_id')) {
            $query->where('blog_todos.assigned_to', $request->input('assignee_id'));
        }

        match ($sort) {
            'author' => $query
                ->leftJoin('blog_posts as sort_posts', 'sort_posts.id', '=', 'blog_todos.blog_post_id')
                ->leftJoin('users as sort_authors', 'sort_authors.id', '=', 'sort_posts.user_id')
                ->orderBy('sort_authors.name', $direction)
                ->orderBy('sort_authors.first_name', $direction),
            'assignee' => $query
                ->leftJoin('users as sort_assignees', 'sort_assignees.id', '=', 'blog_todos.assigned_to')
                ->orderByRaw('sort_assignees.name is null')
                ->orderBy('sort_assignees.name', $direction)
                ->orderBy('sort_assignees.first_name', $direction),
            default => $query->orderBy('blog_todos.'.$sort, $direction),
        };

        $todos = $query->orderBy('blog_todos.id')->paginate(25)->withQueryString();

        return view('admin.todo.index', [
            'assignees' => $this->assignees(),
            'authors' => $this->authors(),
            'direction' => $direction,
            'organizations' => $this->organizations(),
            'selectedOrganizationId' => $selectedOrganizationId,
            'sort' => $sort,
            'statuses' => self::STATUSES,
            'todos' => $todos,
        ]);
    }

    public function update(Request $request, BlogTodo $todo): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
        ]);

        $todo->load(['blogPost.coAuthors', 'blogPost.user']);
        $this->ensureTodoCoherence($todo);

        $assignedTo = $data['assigned_to'] ?? null;

        if ($assignedTo !== null) {
            $assignee = User::findOrFail($assignedTo);

            if (! $this->canAssignToTodo($todo, $assignee)) {
                abort(422, __('blog.admin_todo_invalid_assignee'));
            }
        }

        $todo->update([
            'title' => $data['title'],
            'status' => $data['status'],
            'assigned_to' => $assignedTo,
        ]);

        return back()->with('success', __('blog.admin_todo_updated'));
    }

    public function destroy(BlogTodo $todo): RedirectResponse
    {
        $todo->load('blogPost');
        $this->ensureTodoCoherence($todo);

        DB::transaction(fn () => $todo->delete());

        return back()->with('success', __('blog.admin_todo_deleted'));
    }

    private function ensureTodoCoherence(BlogTodo $todo): void
    {
        abort_if(! $todo->blogPost, 404);
        abort_if($todo->organization_id !== $todo->blogPost->organization_id, 422, __('blog.admin_todo_incoherent'));
    }

    private function canAssignToTodo(BlogTodo $todo, User $user): bool
    {
        if ($user->organization_id !== $todo->organization_id) {
            return false;
        }

        if ($todo->blogPost->user_id === $user->id) {
            return true;
        }

        return $todo->blogPost->coAuthors()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'coauthor')
            ->exists();
    }

    private function organizations(): Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    private function authors(): Collection
    {
        return User::whereIn('id', BlogPost::select('user_id'))
            ->orderBy('name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'name', 'email', 'organization_id']);
    }

    private function assignees(): Collection
    {
        return User::whereIn('id', BlogTodo::query()->whereNotNull('assigned_to')->select('assigned_to'))
            ->orderBy('name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'name', 'email', 'organization_id']);
    }
}
