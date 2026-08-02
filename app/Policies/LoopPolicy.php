<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class LoopPolicy
{
    /**
     * Source of truth for "who can create a Loop" via the member-facing flow
     * (LoopController). The platform super-admin flow (AdminLoopController)
     * is deliberately NOT bypassed here: is_admin never grants an implicit
     * tenant — a super-admin creates only through the explicitly
     * Organization-scoped admin form.
     */
    public function create(User $user, Organization $organization): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        if ($user->organization_id !== $organization->id) {
            return false;
        }

        if (! $organization->loops_enabled) {
            return false;
        }

        if ($organization->admin_id === $user->id) {
            return true;
        }

        return (bool) $organization->members_can_create_loops;
    }
}
