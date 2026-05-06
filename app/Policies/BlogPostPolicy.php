<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;

class BlogPostPolicy
{
    public function create(User $user): bool
    {
        return !$user->banned_at;
    }

    public function update(User $user, BlogPost $post): bool
    {
        return $user->id === $post->user_id || $user->is_admin;
    }

    public function delete(User $user, BlogPost $post): bool
    {
        return $user->id === $post->user_id || $user->is_admin;
    }
}
