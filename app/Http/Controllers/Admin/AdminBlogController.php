<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminBlogController extends Controller
{
    public function index(Request $request): View
    {
        $query = BlogPost::with('user')->withCount(['comments', 'likes']);

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

        return view('admin.blog.index', compact('posts'));
    }

    public function updateStatus(Request $request, BlogPost $post): RedirectResponse
    {
        $request->validate(['status' => 'required|in:draft,pending,published,archived']);

        if ($request->status === 'published' && !$post->published_at) {
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
}
