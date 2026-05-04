<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BelongsToTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        try {
            $community = app('current_community');
        } catch (\Exception $e) {
            return;
        }

        if ($community) {
            $builder->where($model->getTable() . '.community_id', $community->id);
        }
    }
}
