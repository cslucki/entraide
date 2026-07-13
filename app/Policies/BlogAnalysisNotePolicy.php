<?php

namespace App\Policies;

use App\Models\BlogAnalysisNote;
use App\Models\User;

class BlogAnalysisNotePolicy
{
    public function viewAny(User $user, $post): bool
    {
        if (! $this->resourceBelongsToCurrentOrganization($post)) {
            return false;
        }

        return $user->is_admin
            || $user->id === $post->user_id
            || $post->coAuthors()->where('user_id', $user->id)->exists();
    }

    public function view(User $user, BlogAnalysisNote $note): bool
    {
        if (! $this->resourceBelongsToCurrentOrganization($note)) {
            return false;
        }

        return $user->is_admin
            || $user->id === $note->user_id
            || $note->post->coAuthors()->where('user_id', $user->id)->exists();
    }

    public function create(User $user, $post): bool
    {
        if (! $this->resourceBelongsToCurrentOrganization($post)) {
            return false;
        }

        return $user->is_admin
            || $user->id === $post->user_id
            || $post->coAuthors()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, BlogAnalysisNote $note): bool
    {
        if (! $this->resourceBelongsToCurrentOrganization($note)) {
            return false;
        }

        return $user->is_admin || $user->id === $note->user_id;
    }

    public function delete(User $user, BlogAnalysisNote $note): bool
    {
        if (! $this->resourceBelongsToCurrentOrganization($note)) {
            return false;
        }

        return $user->is_admin || $user->id === $note->user_id;
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
