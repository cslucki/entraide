<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function delete(User $user, ServiceRequest $request): bool
    {
        return $user->id === $request->user_id;
    }
}
