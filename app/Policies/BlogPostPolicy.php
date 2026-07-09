<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;

class BlogPostPolicy
{
    public function create(User $user): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return ! $user->banned_at;
    }

    public function update(User $user, BlogPost $post): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! $this->resourceBelongsToCurrentOrganization($post)) {
            return false;
        }

        if ($user->id === $post->user_id) {
            return true;
        }

        return $post->coAuthors()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, BlogPost $post): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->resourceBelongsToCurrentOrganization($post)
            && $user->id === $post->user_id;
    }

    public function manageCoAuthors(User $user, BlogPost $post): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->resourceBelongsToCurrentOrganization($post)
            && $user->id === $post->user_id;
    }

    private function resourceBelongsToCurrentOrganization($resource): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $resource->organization_id === $org->id;
    }
}
