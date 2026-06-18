<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Tag;
use App\Services\BlogAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'img', 'b', 'i', 'strong', 'em', 'u', 'br', 'a', 'code',
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

    public function store(Request $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $this->authorize('create', BlogPost::class);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string|min:50',
            'image' => 'nullable|image|max:2048',
            'status' => 'required|in:draft,published',
            'category_id' => 'required|uuid|exists:categories,id',
            'tags' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
        ]);

        $data['content'] = $this->sanitizeHtml($data['content']);

        if (! Category::where('id', $data['category_id'])->where('organization_id', $organization->id)->exists()) {
            return back()->withErrors(['category_id' => 'Catégorie invalide.'])->withInput();
        }

        $data['user_id'] = auth()->id();
        $data['organization_id'] = $organization->id;
        $data['slug'] = Str::slug($data['title']);
        $data['published_at'] = $data['status'] === 'published' ? now() : null;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        $post = BlogPost::create($data);

        if (! empty($data['tags'])) {
            $tagIds = collect(array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 10))
                ->map(fn ($name) => Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'slug' => Str::slug($name)])->id)
                ->all();
            $post->tags()->sync($tagIds);
        }

        return redirect()->route('blog.show', $post)->with('success', 'Article publié avec succès.');
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

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string|min:50',
            'image' => 'nullable|image|max:2048',
            'status' => 'required|in:draft,pending,published,archived',
            'category_id' => 'required|uuid|exists:categories,id',
            'tags' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
        ]);

        $data['content'] = $this->sanitizeHtml($data['content']);

        if (! Category::where('id', $data['category_id'])->where('organization_id', $organization->id)->exists()) {
            return back()->withErrors(['category_id' => 'Catégorie invalide.'])->withInput();
        }

        if ($data['status'] === 'published' && ! $post->published_at) {
            $data['published_at'] = now();
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        $post->update($data);

        if (isset($data['tags'])) {
            $tagIds = collect(array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 10))
                ->map(fn ($name) => Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'slug' => Str::slug($name)])->id)
                ->all();
            $post->tags()->sync($tagIds);
        }

        return redirect()->route('blog.show', $post)->with('success', 'Article mis à jour.');
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

        return back()->with('success', 'Article publié.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('delete', $post);
        $post->delete();

        return redirect()->route('blog.my-posts')->with('success', 'Article supprimé.');
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

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|max:5120|mimes:jpeg,png,webp,gif',
        ]);

        $path = $request->file('image')->store('blog/images', 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)]);
    }

    public function aiGenerate(Request $request, BlogAiService $ai): JsonResponse
    {
        return $this->handleAi($request, $ai, 'generate');
    }

    public function aiCorrect(Request $request, BlogAiService $ai): JsonResponse
    {
        return $this->handleAi($request, $ai, 'correct');
    }

    public function aiRemaining(Request $request, BlogAiService $ai): JsonResponse
    {
        $request->validate(['post_id' => 'required|string|exists:blog_posts,id']);

        $post = BlogPost::findOrFail($request->input('post_id'));
        $user = $request->user();

        $this->checkPostAccess($post, $user);

        return response()->json([
            'generate' => $ai->remainingCount($post, $user, 'blog_generate'),
            'correct' => $ai->remainingCount($post, $user, 'blog_correct'),
        ]);
    }

    private function handleAi(Request $request, BlogAiService $ai, string $mode): JsonResponse
    {
        try {
            $request->validate(['post_id' => 'required|string|exists:blog_posts,id']);

            $post = BlogPost::findOrFail($request->input('post_id'));
            $user = $request->user();

            $this->checkPostAccess($post, $user);

            $feature = $mode === 'generate' ? 'blog_generate' : 'blog_correct';
            $remaining = $ai->remainingCount($post, $user, $feature);

            if ($remaining <= 0 && ! $user->is_admin) {
                return response()->json(['error' => 'Limite de 3 utilisations atteinte pour cet article.'], 429);
            }

            if ($mode === 'correct') {
                $request->validate(['content' => 'required|string|min:10']);
                $post->content = $request->input('content');
            }

            $result = $mode === 'generate'
                ? $ai->generate($post, $user)
                : $ai->correct($post, $user);

            $newRemaining = $ai->remainingCount($post, $user, $feature);

            return response()->json([
                'content' => $result,
                'remaining' => [
                    'generate' => $feature === 'blog_generate' ? $newRemaining : $ai->remainingCount($post, $user, 'blog_generate'),
                    'correct' => $feature === 'blog_correct' ? $newRemaining : $ai->remainingCount($post, $user, 'blog_correct'),
                ],
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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
