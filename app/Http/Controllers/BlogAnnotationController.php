<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\BlogPostAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
            'origin' => ['nullable', 'string', Rule::in(['human', 'ai_method'])],
            'method_key' => ['nullable', 'string', Rule::in(['explorer', 'clarifier', 'slow_down', 'invent'])],
            'ai_interaction_id' => ['nullable', 'uuid', Rule::exists('ai_interactions', 'id')],
        ]);

        $origin = $data['origin'] ?? 'human';

        if ($origin === 'ai_method' && empty($data['method_key'])) {
            return response()->json([
                'message' => __('validation.required', ['attribute' => 'method_key']),
            ], 422);
        }

        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $post->id,
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'selected_text' => $this->plainText($data['selected_text']),
            'content' => $this->plainText($data['content']),
            'start_offset' => $data['start_offset'] ?? null,
            'end_offset' => $data['end_offset'] ?? null,
            'origin' => $origin,
            'method_key' => $origin === 'ai_method' ? ($data['method_key'] ?? null) : null,
            'ai_interaction_id' => $origin === 'ai_method' ? ($data['ai_interaction_id'] ?? null) : null,
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
            'origin' => $a->origin ?? 'human',
            'method_key' => $a->method_key,
            'method_label' => $a->method_key ? __('blog.method_'.$a->method_key) : null,
            'source_label' => ($a->origin ?? 'human') === 'ai_method' ? __('blog.annotation_source_ai_method') : __('blog.annotation_source_human'),
            'requested_by_label' => ($a->origin ?? 'human') === 'ai_method' ? __('blog.annotation_requested_by', ['name' => $a->user?->fullName ?? __('blog.legend_deleted_user')]) : null,
            'ai_interaction_id' => $a->ai_interaction_id,
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

    private function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\?php|<\%|<\?xml/i', '', $text);
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text);
        $text = preg_replace('/```[a-z0-9_-]*\s*/i', '', $text);
        $text = str_replace('```', '', $text);
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text);
        $text = preg_replace('/^\s{0,3}(?:-{3,}|_{3,}|\*{3,})\s*$/m', '', $text);
        $text = preg_replace('/^\s{0,3}>\s?/m', '', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text);
        $text = preg_replace('/__(.*?)__/s', '$1', $text);
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '$1', $text);
        $text = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '$1', $text);
        $text = preg_replace('/^\s*[-*+]\s+/m', '', $text);
        $text = preg_replace('/^\s*\d+[.)]\s+/m', '', $text);
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $text);
        $text = str_replace(['**', '__', '*'], '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\h*\n\h*/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim((string) $text);
    }
}
