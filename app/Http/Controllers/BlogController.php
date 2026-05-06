<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function index(): View
    {
        $recentPosts = BlogPost::published()
            ->with('user')
            ->latest('published_at')
            ->paginate(12);

        $popularPosts = BlogPost::published()
            ->with('user')
            ->orderByDesc('views_count')
            ->limit(5)
            ->get();

        return view('blog.index', compact('recentPosts', 'popularPosts'));
    }

    public function show(string $slug): View
    {
        $post = BlogPost::published()
            ->where('slug', $slug)
            ->with('user')
            ->firstOrFail();

        $post->increment('views_count');

        return view('blog.show', compact('post'));
    }
}
