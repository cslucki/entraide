<?php

namespace App\Policies;

use App\Models\FeedPost;
use App\Models\User;

class FeedPostPolicy
{
    public function create(User $user): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $user->organization_id === $org->id
            && ($org->admin_id === $user->id || $user->is_admin);
    }

    public function viewAny(User $user): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $user->organization_id === $org->id;
    }

    public function view(User $user, FeedPost $feedPost): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $user->organization_id === $org->id
            && $feedPost->organization_id === $org->id;
    }

    public function react(User $user, FeedPost $feedPost): bool
    {
        return $this->view($user, $feedPost);
    }

    public function comment(User $user, FeedPost $feedPost): bool
    {
        return $this->view($user, $feedPost);
    }

    public function update(User $user, FeedPost $feedPost): bool
    {
        return $this->resourceBelongsToCurrentOrganization($feedPost)
            && ($user->id === $feedPost->user_id || $user->is_admin);
    }

    public function delete(User $user, FeedPost $feedPost): bool
    {
        return $this->resourceBelongsToCurrentOrganization($feedPost)
            && ($user->id === $feedPost->user_id || $user->is_admin);
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
