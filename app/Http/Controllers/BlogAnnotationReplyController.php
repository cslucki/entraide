<?php

namespace App\Http\Controllers;

use App\Models\BlogAnnotationReply;
use App\Models\BlogPost;
use App\Models\BlogPostAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BlogAnnotationReplyController extends Controller
{
    public function index(BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $replies = $annotation->replies()
            ->with('user')
            ->oldest()
            ->get()
            ->map(fn (BlogAnnotationReply $r) => $this->serialize($r));

        return response()->json(['replies' => $replies])
            ->header('Cache-Control', 'no-cache, private');
    }

    public function store(Request $request, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $data = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $annotation->id,
            'user_id' => $request->user()->id,
            'content' => $data['content'],
        ]);

        $reply->load('user');

        return response()->json([
            'reply' => $this->serialize($reply),
            'message' => __('blog.annotation_reply_created'),
        ], 201);
    }

    public function update(Request $request, BlogPost $post, BlogPostAnnotation $annotation, BlogAnnotationReply $reply): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        if ($reply->annotation_id !== $annotation->id) {
            abort(404);
        }

        $user = $request->user();
        if ($reply->user_id !== $user->id && ! $user->is_admin) {
            abort(403);
        }

        $data = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $reply->update(['content' => $data['content']]);
        $reply->load('user');

        return response()->json([
            'reply' => $this->serialize($reply),
            'message' => __('blog.annotation_reply_updated'),
        ]);
    }

    public function destroy(Request $request, BlogPost $post, BlogPostAnnotation $annotation, BlogAnnotationReply $reply): Response
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($annotation->blog_post_id !== $post->id) {
            abort(404);
        }

        if ($reply->annotation_id !== $annotation->id) {
            abort(404);
        }

        $user = $request->user();
        if ($reply->user_id !== $user->id && ! $user->is_admin) {
            abort(403);
        }

        $reply->delete();

        return response()->noContent();
    }

    public function orgIndex(string $org, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        return $this->index($post, $annotation);
    }

    public function orgStore(Request $request, string $org, BlogPost $post, BlogPostAnnotation $annotation): JsonResponse
    {
        return $this->store($request, $post, $annotation);
    }

    public function orgUpdate(Request $request, string $org, BlogPost $post, BlogPostAnnotation $annotation, BlogAnnotationReply $reply): JsonResponse
    {
        return $this->update($request, $post, $annotation, $reply);
    }

    public function orgDestroy(Request $request, string $org, BlogPost $post, BlogPostAnnotation $annotation, BlogAnnotationReply $reply): JsonResponse
    {
        return $this->destroy($request, $post, $annotation, $reply);
    }

    private function serialize(BlogAnnotationReply $r): array
    {
        $user = request()->user();

        return [
            'id' => $r->id,
            'content' => $r->content,
            'author_name' => $r->user?->fullName ?? __('blog.legend_deleted_user'),
            'author_id' => $r->user_id,
            'created_at' => $r->created_at->toIso8601String(),
            'created_at_human' => $r->created_at->diffForHumans(),
            'can_edit' => $user && ($r->user_id === $user->id || $user->is_admin),
            'can_delete' => $user && ($r->user_id === $user->id || $user->is_admin),
        ];
    }
}
