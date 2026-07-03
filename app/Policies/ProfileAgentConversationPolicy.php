<?php

namespace App\Policies;

use App\Models\ProfileAgentConversation;
use App\Models\User;

class ProfileAgentConversationPolicy
{
    public function view(User $user, ProfileAgentConversation $conversation): bool
    {
        if ($conversation->organization_id !== currentOrganization()?->id) {
            return false;
        }

        return $user->id === $conversation->profile_owner_user_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }
}
