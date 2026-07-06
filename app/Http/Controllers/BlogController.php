<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Category;
use App\Models\Tag;
use App\Services\BlogAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BlogController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('throttle:10,1', only: ['uploadImage']),
            new Middleware('throttle:30,1', only: ['aiGenerate', 'aiCorrect']),
        ];
    }

    private const ALLOWED_HTML_TAGS = [
        'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li',
        'img', 'b', 'i', 'strong', 'em', 'u', 'br', 'a', 'code', 'pre',
        'table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot',
        'caption', 'col', 'colgroup',
    ];

    public function index(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $recentPosts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with(['user', 'category', 'tags'])
            ->withCount(['comments', 'likes'])
            ->latest('published_at')
            ->paginate(12);

        $popularPosts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with('user')
            ->orderByDesc('views_count')
            ->limit(5)
            ->get();

        $categories = Category::where('organization_id', $organization->id)->withCount([
            'blogPosts' => fn ($q) => $q->published()->where('blog_posts.organization_id', $organization->id),
        ])->get();

        $popularTags = Tag::withCount([
            'blogPosts' => fn ($q) => $q->published()->where('blog_posts.organization_id', $organization->id),
        ])
            ->orderByDesc('blog_posts_count')
            ->limit(30)
            ->get()
            ->filter(fn ($t) => $t->blog_posts_count > 0)
            ->take(20);

        return view('blog.index', compact('recentPosts', 'popularPosts', 'categories', 'popularTags'));
    }

    public function byCategory(string $slug): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $category = Category::where('slug', $slug)->where('organization_id', $organization->id)->firstOrFail();

        $posts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with(['user', 'category', 'tags'])
            ->withCount(['comments', 'likes'])
            ->where('category_id', $category->id)
            ->latest('published_at')
            ->paginate(12);

        return view('blog.category', compact('category', 'posts'));
    }

    public function orgByCategory(string $org, string $slug): View
    {
        return $this->byCategory($slug);
    }

    public function byTag(string $slug): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $tag = Tag::where('slug', $slug)->firstOrFail();

        $posts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->with(['user', 'category', 'tags'])
            ->withCount(['comments', 'likes'])
            ->whereHas('tags', fn ($q) => $q->where('slug', $slug))
            ->latest('published_at')
            ->paginate(12);

        return view('blog.tag', compact('tag', 'posts'));
    }

    public function orgByTag(string $org, string $slug): View
    {
        return $this->byTag($slug);
    }

    public function show(BlogPost $post): View
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        if ($post->status !== 'published' && auth()->id() !== $post->user_id && ! auth()->user()?->is_admin) {
            abort(404);
        }

        $post->increment('views_count');
        $post->load(['user', 'category', 'tags', 'comments.user', 'comments.replies.user'])
            ->loadCount('likes');

        $relatedPosts = BlogPost::published()
            ->where('organization_id', $organization->id)
            ->where('category_id', $post->category_id)
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(3)
            ->get();

        $isLiked = auth()->check() && $post->isLikedBy(auth()->user());

        return view('blog.show', compact('post', 'relatedPosts', 'isLiked'));
    }

    public function orgShow(string $org, BlogPost $post): View
    {
        return $this->show($post);
    }

    public function create(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);
        $categories = Category::where('organization_id', $organization->id)->orderBy('name_b2c')->get();
        $tags = Tag::orderBy('name')->get();

        return view('blog.create', compact('organization', 'categories', 'tags'));
    }

    public function orgCreate(string $org): View
    {
        return $this->create();
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);

        $data = $this->validateBlogPostRequest($request, $organization, ['draft', 'published']);

        $data['content'] = $this->sanitizeHtml($data['content']);

        $data['user_id'] = auth()->id();
        $data['organization_id'] = $organization->id;
        $data['slug'] = Str::slug($data['title']);
        $data['published_at'] = $data['status'] === 'published' ? now() : null;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        $post = BlogPost::create($data);

        if (! empty($data['tags'])) {
            $post->tags()->syncWithPivotValues(
                $this->tagIdsFromInput($data['tags'], $organization->id),
                ['organization_id' => $organization->id]
            );
        }

        $message = $data['status'] === 'published' ? __('blog.created_published') : __('blog.created_draft');

        return redirect($this->blogUrl('show', ['post' => $post]))->with('success', $message);
    }

    public function orgStore(Request $request, string $org): RedirectResponse
    {
        return $this->store($request);
    }

    public function edit(BlogPost $post): View
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);
        $categories = Category::where('organization_id', $organization->id)->orderBy('name_b2c')->get();
        $tags = Tag::orderBy('name')->get();

        return view('blog.edit', compact('organization', 'post', 'categories', 'tags'));
    }

    public function orgEdit(string $org, BlogPost $post): View
    {
        return $this->edit($post);
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $data = $this->validateBlogPostRequest($request, $organization, ['draft', 'pending', 'published', 'archived']);

        $data['content'] = $this->sanitizeHtml($data['content']);

        if ($data['status'] === 'published' && ! $post->published_at) {
            $data['published_at'] = now();
        }

        if ($request->boolean('remove_image') && $post->image) {
            Storage::disk('public')->delete($post->image);
            $data['image'] = null;
        }

        if ($request->hasFile('image')) {
            if ($post->image) {
                Storage::disk('public')->delete($post->image);
            }
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        unset($data['remove_image']);

        $post->update($data);

        if (isset($data['tags'])) {
            $post->tags()->syncWithPivotValues(
                $this->tagIdsFromInput($data['tags'], $post->organization_id),
                ['organization_id' => $post->organization_id]
            );
        }

        $this->createAutoSnapshot($post, $data, $request->user());

        return redirect($this->blogUrl('show', ['post' => $post]))->with('success', __('blog.updated'));
    }

    public function orgUpdate(Request $request, string $org, BlogPost $post): RedirectResponse
    {
        return $this->update($request, $post);
    }

    public function publish(BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);
        $post->update([
            'status' => 'published',
            'published_at' => $post->published_at ?? now(),
        ]);

        return back()->with('success', __('blog.published'));
    }

    public function orgPublish(string $org, BlogPost $post): RedirectResponse
    {
        return $this->publish($post);
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('delete', $post);
        $post->delete();

        return redirect($this->blogUrl('my-posts'))->with('success', __('blog.deleted'));
    }

    public function orgDestroy(string $org, BlogPost $post): RedirectResponse
    {
        return $this->destroy($post);
    }

    public function myPosts(): View
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $drafts = BlogPost::where('user_id', auth()->id())
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['draft', 'pending'])
            ->withCount(['comments', 'likes'])
            ->latest()
            ->paginate(15, ['*'], 'drafts');

        $publishedPosts = BlogPost::where('user_id', auth()->id())
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->withCount(['comments', 'likes'])
            ->latest()
            ->paginate(15, ['*'], 'published');

        $comments = BlogComment::where('user_id', auth()->id())
            ->whereHas('post', fn ($q) => $q->where('organization_id', $organization->id))
            ->with('post')
            ->latest()
            ->paginate(15, ['*'], 'comments');

        return view('blog.my-posts', compact('drafts', 'publishedPosts', 'comments'));
    }

    public function orgMyPosts(string $org): View
    {
        return $this->myPosts();
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|max:5120|mimes:jpeg,png,webp,gif',
        ]);

        $org = currentOrganization();
        $user = $request->user();
        $uuid = (string) Str::uuid();
        $ext = $request->file('image')->extension();
        $dir = sprintf('blog/images/%s/%s', $org->id, $user->id);
        $filename = sprintf('%s.%s', $uuid, $ext);
        $path = sprintf('%s/%s', $dir, $filename);
        $request->file('image')->storeAs($dir, $filename, 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)]);
    }

    public function orgUploadImage(Request $request, string $org): JsonResponse
    {
        return $this->uploadImage($request);
    }

    public function createDraft(Request $request): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:500',
            'category_id' => 'nullable|uuid|exists:categories,id',
        ]);

        $post = BlogPost::create([
            'user_id' => $request->user()->id,
            'organization_id' => $organization->id,
            'title' => $request->input('title'),
            'summary' => $request->input('summary', ''),
            'content' => '<p></p>',
            'status' => 'draft',
            'category_id' => $request->input('category_id'),
        ]);

        return response()->json([
            'post_id' => $post->id,
            'edit_url' => $this->blogUrl('edit', ['post' => $post]),
        ]);
    }

    public function orgCreateDraft(Request $request, string $org): JsonResponse
    {
        return $this->createDraft($request);
    }

    public function aiGenerate(Request $request, BlogAiService $ai): JsonResponse
    {
        return $this->handleAi($request, $ai, 'generate');
    }

    public function orgAiGenerate(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiGenerate($request, $ai);
    }

    public function aiCorrect(Request $request, BlogAiService $ai): JsonResponse
    {
        return $this->handleAi($request, $ai, 'correct');
    }

    public function orgAiCorrect(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiCorrect($request, $ai);
    }

    public function aiRemaining(Request $request, BlogAiService $ai): JsonResponse
    {
        $user = $request->user();

        $generateConfig = $ai->checkEnabled('blog_generate', $user);
        $correctConfig = $ai->checkEnabled('blog_correct', $user);

        $providerInfo = $ai->getProviderInfo();

        $result = [
            'generate' => $generateConfig['limit'],
            'correct' => $correctConfig['limit'],
            'limits' => [
                'generate' => $generateConfig['limit'],
                'correct' => $correctConfig['limit'],
            ],
            'provider' => $providerInfo['provider'],
            'model' => $providerInfo['model'],
        ];

        if ($request->has('post_id') && $request->filled('post_id')) {
            $post = $this->resolveBlogPost($request->input('post_id'), $user);

            $result['generate'] = $ai->remainingCount($post, $user, 'blog_generate');
            $result['correct'] = $ai->remainingCount($post, $user, 'blog_correct');
        }

        return response()->json($result);
    }

    public function orgAiRemaining(Request $request, BlogAiService $ai, string $org): JsonResponse
    {
        return $this->aiRemaining($request, $ai);
    }

    private function handleAi(Request $request, BlogAiService $ai, string $mode): JsonResponse
    {
        $post = null;
        $isCreateFlow = false;

        try {
            $user = $request->user();

            $title = $request->input('title');
            $summary = $request->input('summary');

            if ($request->has('post_id') && $request->filled('post_id')) {
                $post = $this->resolveBlogPost($request->input('post_id'), $user);
            } else {
                if ($mode !== 'generate') {
                    $request->validate(['content' => 'required|string|min:10']);
                    $post = new BlogPost;
                    $post->id = (string) Str::uuid();
                    $post->organization_id = currentOrganization()?->id ?? $user->organization_id;
                    $post->user_id = $user->id;
                    $post->content = $request->input('content');
                } else {
                    if (empty($title) || empty($summary)) {
                        return response()->json(['error' => __('blog.ai_need_title_summary')], 422);
                    }

                    $orgId = currentOrganization()?->id ?? $user->organization_id;
                    $post = BlogPost::create([
                        'user_id' => $user->id,
                        'organization_id' => $orgId,
                        'title' => $title,
                        'summary' => $summary,
                        'content' => '<p></p>',
                        'status' => 'draft',
                        'category_id' => $request->input('category_id'),
                    ]);
                    $isCreateFlow = true;
                }
            }

            $feature = $mode === 'generate' ? 'blog_generate' : 'blog_correct';

            $featureConfig = $ai->checkEnabled($feature, $user);
            if (! $featureConfig['enabled']) {
                $label = $mode === 'generate' ? __('blog.ai_label_generation') : __('blog.ai_label_correction');

                return response()->json(['error' => __('blog.ai_disabled', ['label' => $label])], 403);
            }

            $remaining = $ai->remainingCount($post, $user, $feature);

            if ($remaining <= 0) {
                $allowed = $ai->checkEnabled($feature, $user);

                return response()->json(['error' => __('blog.ai_limit_reached', ['limit' => $allowed['limit']])], 429);
            }

            if ($mode === 'correct') {
                $request->validate(['content' => 'required|string|min:10']);
                $post->content = $request->input('content');
            }

            $result = $mode === 'generate'
                ? $ai->generate($post, $user, $title, $summary)
                : $ai->correct($post, $user);

            if ($isCreateFlow) {
                $post->content = $result['content'];
                $post->save();
            }

            $newRemaining = $ai->remainingCount($post, $user, $feature);

            $response = [
                'content' => $result['content'],
                'provider' => $result['provider'],
                'model' => $result['model'],
                'limit' => $result['limit'],
                'remaining' => [
                    'generate' => $feature === 'blog_generate' ? $newRemaining : $ai->remainingCount($post, $user, 'blog_generate'),
                    'correct' => $feature === 'blog_correct' ? $newRemaining : $ai->remainingCount($post, $user, 'blog_correct'),
                ],
            ];

            if ($isCreateFlow) {
                $response['post_id'] = $post->id;
                $response['edit_url'] = $this->blogUrl('edit', ['post' => $post]);
            }

            return response()->json($response);
        } catch (HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            if ($isCreateFlow && $post?->exists) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'post_id' => $post->id,
                    'edit_url' => $this->blogUrl('edit', ['post' => $post]),
                ]);
            }

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function resolveBlogPost(string $postId, $user): BlogPost
    {
        $post = BlogPost::find($postId);
        if ($post) {
            $this->checkPostAccess($post, $user);

            return $post;
        }

        $temp = new BlogPost;
        $temp->id = $postId;
        $temp->organization_id = currentOrganization()?->id ?? $user->organization_id;
        $temp->user_id = $user->id;

        return $temp;
    }

    private function checkPostAccess(BlogPost $post, $user): void
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }
        if ($user->id !== $post->user_id && ! $user->is_admin) {
            abort(403);
        }
    }

    private function blogUrl(string $name, array $parameters = []): string
    {
        $organization = request()->route('organization') ?: currentOrganization()?->slug;

        if ($organization && Route::has('organization.blog.'.$name)) {
            return route('organization.blog.'.$name, ['organization' => $organization, ...$parameters]);
        }

        return route('blog.'.$name, $parameters);
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

    /**
     * Drafts stay lightweight; publication requires the public fields.
     * Empty TipTap content is persisted as a minimal HTML paragraph to satisfy the DB constraint.
     */
    private function validateBlogPostRequest(Request $request, $organization, array $allowedStatuses): array
    {
        $isPublished = $request->input('status') === 'published';

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'summary' => [$isPublished ? 'required' : 'nullable', 'string', 'max:500'],
            'content' => [$isPublished ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($isPublished): void {
                if ($isPublished && $this->plainTextContent((string) $value) === '') {
                    $fail(__('blog.validation_content_required'));
                }
            }],
            'image' => ['nullable', 'image', 'max:5120', 'mimes:jpeg,png,webp,gif'],
            'remove_image' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in($allowedStatuses)],
            'category_id' => [$isPublished ? 'required' : 'nullable', 'uuid', 'exists:categories,id'],
            'tags' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
        ], [
            'title.required' => __('blog.validation_title_required'),
            'summary.required' => __('blog.validation_summary_required'),
            'content.required' => __('blog.validation_content_required'),
            'category_id.required' => __('blog.validation_category_required'),
            'category_id.exists' => __('blog.validation_category_invalid'),
            'image.image' => __('blog.validation_image_valid'),
            'image.mimes' => __('blog.validation_image_format'),
            'image.max' => __('blog.validation_image_size'),
        ]);

        $validator->after(function ($validator) use ($request, $organization): void {
            $categoryId = $request->input('category_id');
            if ($categoryId && ! Category::where('id', $categoryId)->where('organization_id', $organization->id)->exists()) {
                $validator->errors()->add('category_id', __('blog.validation_category_invalid'));
            }
        });

        $data = $validator->validate();

        $data['content'] = filled($data['content'] ?? null) ? $data['content'] : '<p></p>';
        $data['category_id'] = filled($data['category_id'] ?? null) ? $data['category_id'] : null;

        return $data;
    }

    private function plainTextContent(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);

        return trim($text);
    }

    /** @return array<int, string> */
    private function tagIdsFromInput(?string $input, string $organizationId): array
    {
        $tagIds = [];
        $seenSlugs = [];

        foreach (explode(',', (string) $input) as $rawName) {
            $name = trim(preg_replace('/^#+/', '', trim($rawName)) ?? '');
            $slug = Str::slug($name);

            if ($name === '' || $slug === '' || isset($seenSlugs[$slug])) {
                continue;
            }

            $seenSlugs[$slug] = true;
            $tagIds[] = Tag::firstOrCreate(
                ['slug' => $slug, 'organization_id' => $organizationId],
                ['name' => $name, 'slug' => $slug]
            )->id;

            if (count($tagIds) >= 10) {
                break;
            }
        }

        return $tagIds;
    }

    private function createAutoSnapshot(BlogPost $post, array $data, $user): void
    {
        $lastSnapshot = $post->snapshots()->latest()->first();

        $sanitizedContent = $this->sanitizeHtml($data['content']);

        $changed = (
            ! $lastSnapshot
            || $lastSnapshot->title !== $data['title']
            || $lastSnapshot->summary !== ($data['summary'] ?? null)
            || $lastSnapshot->content !== $sanitizedContent
            || $lastSnapshot->meta_title !== ($data['meta_title'] ?? null)
            || $lastSnapshot->meta_description !== ($data['meta_description'] ?? null)
            || $lastSnapshot->status !== ($data['status'] ?? $post->status)
        );

        if (! $changed) {
            return;
        }

        BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => __('blog.snapshot_auto_prefix').now()->format('Y-m-d H:i'),
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'content' => $sanitizedContent,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'status' => $data['status'] ?? $post->status,
            'created_by' => $user->id,
        ]);
    }
}
