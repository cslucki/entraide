<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentOrganization;
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

    private function resolveOrganization(): mixed
    {
        return CurrentOrganization::get();
    }
}
