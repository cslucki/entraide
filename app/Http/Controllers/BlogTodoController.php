<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\BlogTodo;
use App\Models\BlogTodoThread;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogTodoController extends Controller
{
    public function index(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $todos = $post->todos()
            ->with(['user', 'assignedTo', 'threads.user'])
            ->orderBy('position')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($todo) => $this->serialize($todo, $post, $request->user()));

        return response()->json(['todos' => $todos]);
    }

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = $this->authorizeTodoAccess($post);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'string', 'uuid'],
        ]);

        $user = $request->user();
        $assignedTo = array_key_exists('assigned_to', $validated) ? $validated['assigned_to'] : $user->id;

        if (! $this->isAuthor($post, $user)) {
            if (array_key_exists('assigned_to', $validated) && $assignedTo !== $user->id) {
                return response()->json(['message' => __('blog.todo_action_not_allowed')], 403);
            }

            $assignedTo = $user->id;
        }

        if ($assignedTo !== null && $assignedTo !== '' && ! $this->canAssignToUser($post, $assignedTo)) {
            return response()->json(['message' => __('blog.todo_invalid_assignee')], 422);
        }

        $maxPosition = $post->todos()->max('position') ?? 0;

        $todo = $post->todos()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'assigned_to' => $assignedTo ?: null,
            'title' => $validated['title'],
            'status' => 'todo',
            'position' => $maxPosition + 1,
        ]);

        $todo->load(['user', 'assignedTo', 'threads.user']);

        return response()->json([
            'message' => __('blog.todo_created'),
            'todo' => $this->serialize($todo, $post, $request->user()),
        ], 201);
    }

    public function update(Request $request, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        $this->authorizeTodoAccess($post, $todo);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:todo,in_progress,done'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'uuid'],
        ]);

        $user = $request->user();

        if (array_key_exists('title', $validated) && ! $this->canEditTodo($post, $todo, $user)) {
            return response()->json(['message' => __('blog.todo_action_not_allowed')], 403);
        }

        if (array_key_exists('status', $validated)) {
            if (! $this->canChangeTodoStatus($post, $todo, $user)) {
                return response()->json(['message' => __('blog.todo_action_not_allowed')], 403);
            }
        }

        if (array_key_exists('assigned_to', $validated)) {
            if (! $this->canAssignTodo($post, $todo, $user)) {
                return response()->json(['message' => __('blog.todo_action_not_allowed')], 403);
            }

            if ($validated['assigned_to'] === '' || $validated['assigned_to'] === null) {
                $validated['assigned_to'] = null;
            } elseif (! $this->canAssignToUser($post, $validated['assigned_to'])) {
                return response()->json(['message' => __('blog.todo_invalid_assignee')], 422);
            }
        }

        $todo->update($validated);
        $todo->load(['user', 'assignedTo', 'threads.user']);

        return response()->json([
            'message' => __('blog.todo_updated'),
            'todo' => $this->serialize($todo, $post, $request->user()),
        ]);
    }

    public function destroy(Request $request, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        $this->authorizeTodoAccess($post, $todo);

        $user = $request->user();

        if (! $this->canDeleteTodo($post, $todo, $user)) {
            return response()->json(['message' => __('blog.todo_not_allowed')], 403);
        }

        $todo->delete();

        return response()->json(['message' => __('blog.todo_deleted')]);
    }

    public function threadStore(Request $request, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        $this->authorizeTodoAccess($post, $todo);

        if (! $this->canEditTodo($post, $todo, $request->user())) {
            return response()->json(['message' => __('blog.todo_action_not_allowed')], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $thread = $todo->threads()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $thread->load('user');

        return response()->json([
            'message' => __('blog.todo_thread_added'),
            'thread' => $this->serializeThread($thread),
        ], 201);
    }

    public function threadDestroy(Request $request, BlogPost $post, BlogTodo $todo, BlogTodoThread $thread): JsonResponse
    {
        $this->authorizeTodoAccess($post, $todo);

        if ($thread->todo_id !== $todo->id) {
            abort(404);
        }

        if ($thread->user_id !== $request->user()->id) {
            return response()->json(['message' => __('blog.todo_thread_not_owner')], 403);
        }

        $thread->delete();

        return response()->json(['message' => __('blog.todo_thread_deleted')]);
    }

    public function orgIndex(Request $request, string $organization, BlogPost $post): JsonResponse
    {
        return $this->callWithOrg($request, $organization, fn () => $this->index($request, $post));
    }

    public function orgStore(Request $request, string $organization, BlogPost $post): JsonResponse
    {
        return $this->callWithOrg($request, $organization, fn () => $this->store($request, $post));
    }

    public function orgUpdate(Request $request, string $organization, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        return $this->callWithOrg($request, $organization, fn () => $this->update($request, $post, $todo));
    }

    public function orgDestroy(Request $request, string $organization, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        return $this->callWithOrg($request, $organization, fn () => $this->destroy($request, $post, $todo));
    }

    public function orgThreadStore(Request $request, string $organization, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        return $this->callWithOrg($request, $organization, fn () => $this->threadStore($request, $post, $todo));
    }

    public function orgThreadDestroy(Request $request, string $organization, BlogPost $post, BlogTodo $todo, BlogTodoThread $thread): JsonResponse
    {
        return $this->callWithOrg($request, $organization, fn () => $this->threadDestroy($request, $post, $todo, $thread));
    }

    private function callWithOrg(Request $request, string $organizationSlug, callable $callback): JsonResponse
    {
        $request->route()->setParameter('organization', $organizationSlug);

        return $callback();
    }

    private function serialize(BlogTodo $todo, BlogPost $post, User $user): array
    {
        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'status' => $todo->status,
            'position' => $todo->position,
            'user_id' => $todo->user_id,
            'assigned_to' => $todo->assigned_to ?? '',
            'assigned_to_name' => $todo->assignedTo?->name,
            'can_edit' => $this->canEditTodo($post, $todo, $user),
            'can_assign' => $this->canAssignTodo($post, $todo, $user),
            'can_change_status' => $this->canChangeTodoStatus($post, $todo, $user),
            'can_complete' => $this->canCompleteTodo($post, $todo, $user),
            'can_reopen' => $this->canReopenTodo($post, $todo, $user),
            'can_delete' => $this->canDeleteTodo($post, $todo, $user),
            'created_at' => $todo->created_at->toISOString(),
            'created_at_human' => $todo->created_at->diffForHumans(),
            'threads' => $todo->threads->map(fn ($t) => $this->serializeThread($t)),
        ];
    }

    private function authorizeTodoAccess(BlogPost $post, ?BlogTodo $todo = null): mixed
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($todo && ($todo->blog_post_id !== $post->id || $todo->organization_id !== $post->organization_id)) {
            abort(404);
        }

        $this->authorize('update', $post);

        return $organization;
    }

    private function isAuthor(BlogPost $post, User $user): bool
    {
        return $post->user_id === $user->id;
    }

    private function isAssigned(BlogTodo $todo, User $user): bool
    {
        return $todo->assigned_to === $user->id;
    }

    private function canEditTodo(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        return $this->isAuthor($post, $user) || $this->isAssigned($todo, $user);
    }

    private function canAssignTodo(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        return $this->isAuthor($post, $user);
    }

    private function canCompleteTodo(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        return $this->canChangeTodoStatus($post, $todo, $user);
    }

    private function canReopenTodo(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        return $this->canChangeTodoStatus($post, $todo, $user);
    }

    private function canChangeTodoStatus(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        if ($todo->assigned_to !== null) {
            return $this->isAssigned($todo, $user);
        }

        return $this->isAuthor($post, $user);
    }

    private function canDeleteTodo(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        if ($this->isAuthor($post, $user)) {
            return true;
        }

        if ($this->isAssigned($todo, $user)) {
            return true;
        }

        return false;
    }

    private function canAssignToUser(BlogPost $post, string $userId): bool
    {
        if ($post->user_id === $userId) {
            return User::where('id', $userId)
                ->where('organization_id', $post->organization_id)
                ->exists();
        }

        return $post->coAuthors()
            ->where('users.id', $userId)
            ->where('users.organization_id', $post->organization_id)
            ->exists();
    }

    private function serializeThread(BlogTodoThread $thread): array
    {
        return [
            'id' => $thread->id,
            'user_id' => $thread->user_id,
            'sender_name' => $thread->user?->name,
            'body' => $thread->body,
            'created_at' => $thread->created_at->toISOString(),
            'created_at_human' => $thread->created_at->diffForHumans(),
        ];
    }
}
