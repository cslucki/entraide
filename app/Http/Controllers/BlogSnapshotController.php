<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BlogSnapshotController extends Controller
{
    private const ALLOWED_HTML_TAGS = [
        'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li',
        'img', 'b', 'i', 'strong', 'em', 'u', 'br', 'a', 'code', 'pre',
        'table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot',
        'caption', 'col', 'colgroup',
    ];

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $lastSnapshot = $post->snapshots()->latest()->first();
        $sanitizedContent = $this->sanitizeHtml($data['content']);

        $changed = (
            ! $lastSnapshot
            || $lastSnapshot->title !== $data['title']
            || $lastSnapshot->summary !== ($data['summary'] ?? null)
            || $lastSnapshot->content !== $sanitizedContent
            || $lastSnapshot->meta_title !== ($data['meta_title'] ?? null)
            || $lastSnapshot->meta_description !== ($data['meta_description'] ?? null)
            || $lastSnapshot->status !== ($data['status'] ?? null)
        );

        if (! $changed && $lastSnapshot) {
            $lastSnapshot->update([
                'name' => $data['name'],
                'comment' => $data['comment'] ?? null,
            ]);

            return response()->json([
                'id' => $lastSnapshot->id,
                'name' => $lastSnapshot->name,
                'created_at' => $lastSnapshot->created_at,
                'message' => __('blog.snapshot_named'),
                'updated' => true,
            ]);
        }

        $snapshot = BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => $data['name'],
            'comment' => $data['comment'] ?? null,
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'content' => $sanitizedContent,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'status' => $data['status'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'id' => $snapshot->id,
            'name' => $snapshot->name,
            'created_at' => $snapshot->created_at,
            'message' => __('blog.snapshot_created'),
        ]);
    }

    public function index(BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $limit = min((int) request()->input('limit', 5), 50);
        $offset = (int) request()->input('offset', 0);

        $snapshotsQuery = $post->snapshots()->with(['creator', 'updater'])->latest();
        $total = $snapshotsQuery->count();

        $snapshots = $snapshotsQuery->skip($offset)->take($limit + 1)->get();
        $hasMore = $snapshots->count() > $limit;
        if ($hasMore) {
            $snapshots->pop();
        }

        return response()->json([
            'snapshots' => $snapshots->map(fn (BlogSnapshot $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'comment' => $s->comment,
                'created_at' => $s->created_at->diffForHumans(),
                'updated_at' => $s->updated_at->diffForHumans(),
                'restored_at' => $s->restored_at?->diffForHumans(),
                'creator_name' => $s->creator?->fullName ?? __('blog.legend_deleted_user'),
                'updater_name' => $s->updater?->fullName ?? __('blog.legend_deleted_user'),
                'is_restored' => $s->restored_at !== null,
            ]),
            'has_more' => $hasMore,
            'total' => $total,
        ]);
    }

    public function restore(BlogPost $post, BlogSnapshot $snapshot): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        if ($snapshot->blog_post_id !== $post->id) {
            abort(404);
        }

        $snapshot->update(['restored_at' => now()]);

        return response()->json([
            'id' => $snapshot->id,
            'title' => $snapshot->title,
            'summary' => $snapshot->summary,
            'content' => $snapshot->content,
            'meta_title' => $snapshot->meta_title,
            'meta_description' => $snapshot->meta_description,
            'status' => $snapshot->status,
        ]);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->store($request, $post);
    }

    public function orgIndex(string $org, BlogPost $post): JsonResponse
    {
        return $this->index($post);
    }

    public function orgRestore(string $org, BlogPost $post, BlogSnapshot $snapshot): JsonResponse
    {
        return $this->restore($post, $snapshot);
    }

    private function sanitizeHtml(string $html): string
    {
        $allowed = self::ALLOWED_HTML_TAGS;

        $html = strip_tags($html, '<'.implode('><', $allowed).'>');

        $html = preg_replace('/<(\w+)\s[^>]*on\w+\s*=\s*["\'][^"\']*["\']/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*javascript\s*:\s*[^"\'>\s]+/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*data\s*:\s*[^"\'>\s]+/i', '<$1', $html);
        $html = preg_replace('/<\?php|<\%|<\%\=|<\?xml/i', '', $html);
        $html = preg_replace('/\{\{.*?\}\}/s', '', $html);
        $html = preg_replace('/<(\w+)[^>]*style\s*=\s*["\'][^"\']*["\']/i', '<$1', $html);

        return $html;
    }
}
