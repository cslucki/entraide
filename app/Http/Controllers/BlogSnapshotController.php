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
        'div', 'iframe',
    ];

    private const ALLOWED_IFRAME_DOMAINS = [
        'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'vimeo.com', 'player.vimeo.com',
        'dailymotion.com', 'www.dailymotion.com',
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
                'title' => $s->title,
                'summary' => $s->summary,
                'content' => $s->content,
                'meta_title' => $s->meta_title,
                'meta_description' => $s->meta_description,
                'status' => $s->status,
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
        // Step 1 — extract valid annotation spans as safe placeholders
        $annotationSpans = [];
        $html = $this->extractAnnotationSpans($html, $annotationSpans);

        // Step 2 — standard sanitization (span is NOT in allowed tags)
        $allowed = self::ALLOWED_HTML_TAGS;
        $html = strip_tags($html, '<'.implode('><', $allowed).'>');

        $html = preg_replace('/<(\w+)\s[^>]*on\w+\s*=\s*["\'][^"\']*["\']/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*javascript\s*:\s*[^"\'>\s]+/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*data\s*:\s*[^"\'>\s]+/i', '<$1', $html);
        $html = preg_replace('/<\?php|<\%|<\%\=|<\?xml/i', '', $html);
        $html = preg_replace('/\{\{.*?\}\}/s', '', $html);
        $html = $this->stripStyleAttribute($html);

        // Remove iframes pointing to non-approved domains
        if (str_contains($html, 'iframe')) {
            $html = $this->filterIframeDomains($html);
        }

        // Step 3 — restore annotation spans from placeholders
        foreach ($annotationSpans as $key => $safeSpan) {
            $html = str_replace($key, $safeSpan, $html);
        }

        return $html;
    }

    private function stripStyleAttribute(string $html): string
    {
        return preg_replace_callback(
            '/<(\w+)\b([^>]*)>/i',
            function (array $match): string {
                $tag = strtolower($match[1]);
                $attrs = $match[2];
                if (in_array($tag, ['col', 'colgroup', 'div'], true)) {
                    return $match[0];
                }
                $attrs = preg_replace('/\s+style\s*=\s*"[^"]*"/i', '', $attrs);
                $attrs = preg_replace("/\s+style\s*=\s*'[^']*'/i", '', $attrs);

                return '<'.$match[1].$attrs.'>';
            },
            $html
        );
    }

    private function filterIframeDomains(string $html): string
    {
        return preg_replace_callback(
            '/(<iframe\b[^>]*>)(.*?)(<\/iframe>)/is',
            function (array $match): string {
                $openTag = $match[1];
                $inner = $match[2];
                $closeTag = $match[3];

                if (preg_match('/\bsrc\s*=\s*"([^"]*)"/i', $openTag, $srcMatch)) {
                    if (! $this->isAllowedIframeDomain($srcMatch[1])) {
                        return '';
                    }
                }

                return $openTag.$inner.$closeTag;
            },
            $html
        );
    }

    private function isAllowedIframeDomain(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }
        $host = strtolower($host);

        return in_array($host, self::ALLOWED_IFRAME_DOMAINS, true);
    }

    private function extractAnnotationSpans(string $html, array &$protected): string
    {
        $previous = null;

        while ($previous !== $html) {
            $previous = $html;
            $html = preg_replace_callback(
                '/<span\b([^>]*)>((?:(?!<\/?span\b).)*)<\/span>/is',
                function (array $match) use (&$protected): string {
                    $attrs = $match[1];
                    $inner = $match[2];

                    if (preg_match('/data-annotation-id\s*=\s*"([^"]+)"/i', $attrs, $idMatch)) {
                        $id = $idMatch[1];
                        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                            $classMatch = [];
                            $hasClass = preg_match('/class\s*=\s*"([^"]*)"/i', $attrs, $classMatch);
                            $classes = $hasClass ? preg_split('/\s+/', trim($classMatch[1])) : [];
                            if (in_array('bp-annotation-mark', $classes, true)) {
                                $openKey = '@@@BPAO_'.count($protected).'@@@';
                                $closeKey = '@@@BPAC_'.count($protected).'@@@';
                                $protected[$openKey] = '<span data-annotation-id="'.$id.'" class="bp-annotation-mark">';
                                $protected[$closeKey] = '</span>';

                                return $openKey.$inner.$closeKey;
                            }
                        }
                    }

                    return $inner;
                },
                $html
            );
        }

        return $html;
    }
}
