<?php

namespace App\Policies;

use App\Models\FeedPost;
use App\Models\User;

class FeedPostPolicy
{
    public function create(User $user): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        if ($user->organization_id !== $org->id) {
            return false;
        }

        if ($org->feed_post_publish_mode === 'members') {
            return true;
        }

        return $org->admin_id === $user->id || $user->is_admin;
    }

    public function viewAny(User $user): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $user->organization_id === $org->id;
    }

    public function view(User $user, FeedPost $feedPost): bool
    {
        if ($user->banned_at) {
            return false;
        }

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

    public function pin(User $user): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        if ($user->organization_id !== $org->id) {
            return false;
        }

        return $user->is_admin || $user->id === $org->admin_id;
    }

    public function update(User $user, FeedPost $feedPost): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $this->resourceBelongsToCurrentOrganization($feedPost)
            && ($user->id === $feedPost->user_id || $user->is_admin || $user->id === $org->admin_id);
    }

    public function delete(User $user, FeedPost $feedPost): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $this->resourceBelongsToCurrentOrganization($feedPost)
            && ($user->id === $feedPost->user_id || $user->is_admin || $user->id === $org->admin_id);
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
