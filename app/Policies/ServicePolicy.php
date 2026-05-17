<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function update(User $user, Service $service): bool
    {
        return $this->resourceBelongsToCurrentOrganization($service)
            && $user->id === $service->user_id;
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->resourceBelongsToCurrentOrganization($service)
            && $user->id === $service->user_id;
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
