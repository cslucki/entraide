<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function index(): View
    {
        $recentPosts = BlogPost::published()
            ->with(['user', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->latest('published_at')
            ->paginate(12);

        $popularPosts = BlogPost::published()
            ->with('user')
            ->orderByDesc('views_count')
            ->limit(5)
            ->get();

        $categories = Category::withCount(['blogPosts' => fn($q) => $q->published()])->get();

        $popularTags = Tag::withCount(['blogPosts' => fn($q) => $q->published()])
            ->orderByDesc('blog_posts_count')
            ->limit(30)
            ->get()
            ->filter(fn($t) => $t->blog_posts_count > 0)
            ->take(20);

        return view('blog.index', compact('recentPosts', 'popularPosts', 'categories', 'popularTags'));
    }

    public function byCategory(string $slug): View
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $posts = BlogPost::published()
            ->with(['user', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->whereHas('categories', fn($q) => $q->where('slug', $slug))
            ->latest('published_at')
            ->paginate(12);

        return view('blog.category', compact('category', 'posts'));
    }

    public function byTag(string $slug): View
    {
        $tag = Tag::where('slug', $slug)->firstOrFail();

        $posts = BlogPost::published()
            ->with(['user', 'categories', 'tags'])
            ->withCount(['comments', 'likes'])
            ->whereHas('tags', fn($q) => $q->where('slug', $slug))
            ->latest('published_at')
            ->paginate(12);

        return view('blog.tag', compact('tag', 'posts'));
    }

    public function show(BlogPost $post): View
    {
        if ($post->status !== 'published' && auth()->id() !== $post->user_id && !auth()->user()?->is_admin) {
            abort(404);
        }

        $post->increment('views_count');
        $post->load(['user', 'categories', 'tags', 'comments.user', 'comments.replies.user']);

        $relatedPosts = BlogPost::published()
            ->with('user')
            ->whereHas('categories', fn($q) => $q->whereIn('categories.id', $post->categories->pluck('id')))
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(3)
            ->get();

        $isLiked = auth()->check() && $post->isLikedBy(auth()->user());

        return view('blog.show', compact('post', 'relatedPosts', 'isLiked'));
    }

    public function create(): View
    {
        $this->authorize('create', BlogPost::class);
        $categories = Category::all();
        $tags = Tag::orderBy('name')->get();
        return view('blog.create', compact('categories', 'tags'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', BlogPost::class);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'summary'          => 'nullable|string|max:500',
            'content'          => 'required|string|min:50',
            'image'            => 'nullable|image|max:2048',
            'status'           => 'required|in:draft,published',
            'categories'       => 'nullable|array',
            'categories.*'     => 'uuid|exists:categories,id',
            'tags'             => 'nullable|string',
            'meta_title'       => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
        ]);

        $data['user_id']      = auth()->id();
        $data['slug']         = Str::slug($data['title']);
        $data['published_at'] = $data['status'] === 'published' ? now() : null;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        $post = BlogPost::create($data);

        if (!empty($data['categories'])) {
            $post->categories()->sync($data['categories']);
        }

        if (!empty($data['tags'])) {
            $tagIds = collect(array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 10))
                ->map(fn($name) => Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'slug' => Str::slug($name)])->id)
                ->all();
            $post->tags()->sync($tagIds);
        }

        return redirect()->route('blog.show', $post)->with('success', 'Article publié avec succès.');
    }

    public function edit(BlogPost $post): View
    {
        $this->authorize('update', $post);
        $categories = Category::all();
        $tags = Tag::orderBy('name')->get();
        return view('blog.edit', compact('post', 'categories', 'tags'));
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'summary'          => 'nullable|string|max:500',
            'content'          => 'required|string|min:50',
            'image'            => 'nullable|image|max:2048',
            'status'           => 'required|in:draft,pending,published,archived',
            'categories'       => 'nullable|array',
            'categories.*'     => 'uuid|exists:categories,id',
            'tags'             => 'nullable|string',
            'meta_title'       => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
        ]);

        if ($data['status'] === 'published' && !$post->published_at) {
            $data['published_at'] = now();
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('blog', 'public');
        }

        $post->update($data);
        $post->categories()->sync($data['categories'] ?? []);

        if (isset($data['tags'])) {
            $tagIds = collect(array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 10))
                ->map(fn($name) => Tag::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'slug' => Str::slug($name)])->id)
                ->all();
            $post->tags()->sync($tagIds);
        }

        return redirect()->route('blog.show', $post)->with('success', 'Article mis à jour.');
    }

    public function publish(BlogPost $post): RedirectResponse
    {
        $this->authorize('update', $post);
        $post->update([
            'status'       => 'published',
            'published_at' => $post->published_at ?? now(),
        ]);
        return back()->with('success', 'Article publié.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $this->authorize('delete', $post);
        $post->delete();
        return redirect()->route('blog.my-posts')->with('success', 'Article supprimé.');
    }

    public function myPosts(): View
    {
        $posts = BlogPost::where('user_id', auth()->id())
            ->withCount(['comments', 'likes'])
            ->latest()
            ->paginate(15);

        return view('blog.my-posts', compact('posts'));
    }
}
