<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\BlogPostAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BlogAnnotationController extends Controller
{
    public function index(BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $annotations = $post->annotations()
            ->with(['user', 'resolvedBy', 'replies.user'])
            ->latest()
            ->get()
            ->map(fn (BlogPostAnnotation $a) => $this->serialize($a));

        return response()->json(['annotations' => $annotations])
            ->header('Cache-Control', 'no-cache, private');
    }

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $data = $request->validate([
            'selected_text' => 'required|string|max:5000',
            'content' => 'required|string|max:5000',
            'start_offset' => 'nullable|integer|min:0',
            'end_offset' => 'nullable|integer|min:0',
        ]);

        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $post->id,
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'selected_text' => $data['selected_text'],
            'content' => $data['content'],
            'start_offset' => $data['start_offset'] ?? null,
            'end_offset' => $data['end_offset'] ?? null,
        ]);

        $annotation->load('user');

        return response()->json([
            'annotation' => $this->serialize($annotation),
            'message' => __('blog.annotation_created'),
        ], 201);
    }

    public function update(Request $request, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        $user = $request->user();
        if ($annotation->user_id !== $user->id && ! $user->is_admin) {
            abort(403);
        }

        $data = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $annotation->update(['content' => $data['content']]);
        $annotation->load('user');

        return response()->json([
            'annotation' => $this->serialize($annotation),
            'message' => __('blog.annotation_updated'),
        ]);
    }

    public function destroy(Request $request, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        $user = $request->user();
        if ($annotation->user_id !== $user->id && ! $user->is_admin) {
            abort(403);
        }

        $annotation->delete();

        return response()->json(['message' => __('blog.annotation_deleted')]);
    }

    public function resolve(Request $request, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        $user = $request->user();
        if ($user->id !== $post->user_id && ! $user->is_admin) {
            abort(403);
        }

        $annotation->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $user->id,
        ]);

        $annotation->load(['user', 'resolvedBy']);

        return response()->json([
            'annotation' => $this->serialize($annotation),
            'message' => __('blog.annotation_resolved'),
        ]);
    }

    public function orgIndex(string $org, BlogPost $post): JsonResponse
    {
        return $this->index($post);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->store($request, $post);
    }

    public function orgUpdate(Request $request, string $org, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        return $this->update($request, $post, $annotation);
    }

    public function orgDestroy(Request $request, string $org, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        return $this->destroy($request, $post, $annotation);
    }

    public function orgResolve(Request $request, string $org, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        return $this->resolve($request, $post, $annotation);
    }

    private function serialize(BlogPostAnnotation $a): array
    {
        $user = request()->user();
        $postOwnerId = $a->blogPost->user_id;

        $replies = $a->relationLoaded('replies')
            ? $a->replies->map(fn ($r) => [
                'id' => $r->id,
                'content' => $r->content,
                'author_name' => $r->user?->fullName ?? __('blog.legend_deleted_user'),
                'author_id' => $r->user_id,
                'created_at' => $r->created_at->toIso8601String(),
                'created_at_human' => $r->created_at->diffForHumans(),
                'can_edit' => $user && ($r->user_id === $user->id || $user->is_admin),
                'can_delete' => $user && ($r->user_id === $user->id || $user->is_admin),
            ])->values()->toArray()
            : [];

        return [
            'id' => $a->id,
            'selected_text' => $a->selected_text,
            'content' => $a->content,
            'status' => $a->status,
            'start_offset' => $a->start_offset,
            'end_offset' => $a->end_offset,
            'author_name' => $a->user?->fullName ?? __('blog.legend_deleted_user'),
            'created_at' => $a->created_at->toIso8601String(),
            'created_at_human' => $a->created_at->diffForHumans(),
            'resolved_at' => $a->resolved_at?->toIso8601String(),
            'resolved_by_name' => $a->resolvedBy?->fullName ?? null,
            'can_edit' => $user && ($a->user_id === $user->id || $user->is_admin),
            'can_delete' => $user && ($a->user_id === $user->id || $user->is_admin),
            'can_resolve' => $user && ($postOwnerId === $user->id || $user->is_admin),
            'replies' => $replies,
        ];
    }
}
