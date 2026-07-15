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
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'string', 'uuid'],
        ]);

        if ($validated['assigned_to'] ?? null) {
            $assigned = User::where('id', $validated['assigned_to'])->first();
            if (! $assigned) {
                return response()->json(['message' => __('blog.todo_invalid_assignee')], 422);
            }
        }

        $maxPosition = $post->todos()->max('position') ?? 0;

        $todo = $post->todos()->create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'assigned_to' => $validated['assigned_to'] ?? $request->user()->id,
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
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:todo,in_progress,done'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'uuid'],
        ]);

        if (array_key_exists('assigned_to', $validated)) {
            if ($validated['assigned_to'] === '' || $validated['assigned_to'] === null) {
                $validated['assigned_to'] = null;
            } else {
                $assigned = User::where('id', $validated['assigned_to'])->first();
                if (! $assigned) {
                    return response()->json(['message' => __('blog.todo_invalid_assignee')], 422);
                }
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
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $user = $request->user();
        
        if (! $this->canDeleteTodo($post, $todo, $user)) {
            return response()->json(['message' => __('blog.todo_not_allowed')], 403);
        }

        $todo->delete();

        return response()->json(['message' => __('blog.todo_deleted')]);
    }

    public function threadStore(Request $request, BlogPost $post, BlogTodo $todo): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

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
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

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
            'can_delete' => $this->canDeleteTodo($post, $todo, $user),
            'created_at' => $todo->created_at->toISOString(),
            'created_at_human' => $todo->created_at->diffForHumans(),
            'threads' => $todo->threads->map(fn ($t) => $this->serializeThread($t)),
        ];
    }

    private function canDeleteTodo(BlogPost $post, BlogTodo $todo, User $user): bool
    {
        if ($post->user_id === $user->id) {
            return true;
        }

        if ($todo->assigned_to === $user->id) {
            return true;
        }

        return false;
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
