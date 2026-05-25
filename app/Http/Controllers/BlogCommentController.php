<?php

namespace App\Http\Controllers;

use App\Models\BlogComment;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlogCommentController extends Controller
{
    public function store(Request $request, BlogPost $post): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $data = $request->validate([
            'content' => 'required|string|max:2000',
            'parent_id' => 'nullable|uuid|exists:blog_comments,id',
        ]);

        if (! empty($data['parent_id'])) {
            $parent = BlogComment::find($data['parent_id']);
            abort_unless($parent && $parent->blog_post_id === $post->id, 404);
        }

        BlogComment::create([
            'blog_post_id' => $post->id,
            'user_id' => auth()->id(),
            'parent_id' => $data['parent_id'] ?? null,
            'content' => $data['content'],
            'is_approved' => true,
        ]);

        return back()->with('success', 'Commentaire ajouté.');
    }

    public function destroy(BlogComment $comment): RedirectResponse
    {
        $organization = currentOrganization();
        $post = $comment->post;
        if (! $organization || ! $post || $post->organization_id !== $organization->id) {
            abort(404);
        }

        abort_unless(auth()->id() === $comment->user_id || auth()->user()->is_admin, 403);
        $comment->delete();

        return back()->with('success', 'Commentaire supprimé.');
    }
}
