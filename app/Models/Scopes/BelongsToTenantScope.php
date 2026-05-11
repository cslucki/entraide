<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BelongsToTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organization = $this->resolveOrganization();

        if ($organization) {
            $builder->where($model->getTable().'.community_id', $organization->id);
        }
    }

    /**
     * Prefer current_organization (canonical), fall back to current_community (legacy).
     * Returns null when neither is bound — non-tenant context (console, admin, tests).
     */
    private function resolveOrganization(): mixed
    {
        if (app()->bound('current_organization')) {
            return app('current_organization');
        }

        if (app()->bound('current_community')) {
            return app('current_community');
        }

        return null;
    }
}
