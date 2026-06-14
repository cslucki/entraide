<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;

class AdminBlogController extends Controller
{
    public function index(Request $request): View
    {
        $query = BlogPost::with(['user', 'organization'])->withCount(['comments', 'likes']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        if ($selectedOrganizationId !== 'all') {
            $query->where('organization_id', $selectedOrganizationId);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', '%'.$request->search.'%')
                    ->orWhere('content', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $posts = $query->latest()->paginate(20)->withQueryString();

        return view('admin.blog.index', compact('organizations', 'posts', 'selectedOrganizationId'));
    }

    private function adminOrganizations(): Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    private function selectedAdminOrganizationId(Request $request): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        return (string) (DefaultOrganizationResolver::resolve()?->getKey() ?? 'all');
    }

    public function edit(BlogPost $post): View
    {
        $categories = Category::orderBy('name_b2c')->get();
        $tags = Tag::orderBy('name')->get();
        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.blog.edit', compact('post', 'categories', 'tags', 'users'));
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_posts,slug,'.$post->id,
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string|min:50',
            'image' => 'nullable|image|max:2048',
            'status' => 'required|in:draft,pending,published,archived',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'tags' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
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
                ->map(fn ($name) => Tag::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'slug' => Str::slug($name)]
                )->id)
                ->all();
            $post->tags()->sync($tagIds);
        }

        return redirect()->route('admin.blog.edit', $post)
            ->with('success', 'Article mis à jour.');
    }

    public function updateStatus(Request $request, BlogPost $post): RedirectResponse
    {
        $request->validate(['status' => 'required|in:draft,pending,published,archived']);

        if ($request->status === 'published' && ! $post->published_at) {
            $post->published_at = now();
        }

        $post->status = $request->status;
        $post->save();

        return back()->with('success', 'Statut mis à jour.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $post->delete();

        return back()->with('success', 'Article supprimé.');
    }

    public function previewMarkdown(Request $request): JsonResponse
    {
        $request->validate(['content' => 'required|string']);

        $converter = new CommonMarkConverter;
        $converter->getEnvironment()->addExtension(new GithubFlavoredMarkdownExtension);

        $html = $converter->convert($request->content);

        return response()->json(['html' => (string) $html]);
    }
}
