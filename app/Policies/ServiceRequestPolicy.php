<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function update(User $user, ServiceRequest $request): bool
    {
        if (! $this->resourceBelongsToCurrentOrganization($request)) {
            return false;
        }

        if ($user->id !== $request->user_id) {
            return false;
        }

        if ($request->transactions()->exists()) {
            return false;
        }

        return true;
    }

    public function delete(User $user, ServiceRequest $request): bool
    {
        return $this->resourceBelongsToCurrentOrganization($request)
            && $user->id === $request->user_id;
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
