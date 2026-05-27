<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Log;

class BelongsToTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organization = $this->resolveOrganization();

        if ($organization) {
            $builder->where($model->getTable().'.organization_id', $organization->id);
        } else {
            Log::warning('BelongsToTenantScope: no Organization resolved, applying whereRaw(0=1) — data inaccessible until a Default Organization exists.');
            $builder->whereRaw('0 = 1');
        }
    }

    private function resolveOrganization(): mixed
    {
        return CurrentOrganization::get();
    }
}
