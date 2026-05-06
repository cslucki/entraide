<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlogCommentController extends Controller
{
    public function store(Request $request, BlogPost $post): RedirectResponse
    {
        $request->validate([
            'content'   => 'required|string|max:2000',
            'parent_id' => 'nullable|uuid|exists:blog_comments,id',
        ]);

        BlogComment::create([
            'blog_post_id' => $post->id,
            'user_id'      => auth()->id(),
            'parent_id'    => $request->parent_id,
            'content'      => $request->content,
            'is_approved'  => true,
        ]);

        return back()->with('success', 'Commentaire ajouté.');
    }

    public function destroy(BlogComment $comment): RedirectResponse
    {
        abort_unless(auth()->id() === $comment->user_id || auth()->user()->is_admin, 403);
        $comment->delete();
        return back()->with('success', 'Commentaire supprimé.');
    }
}
