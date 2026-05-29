<?php

namespace App\Models\Traits;

trait HasOrganizationId
{
    public static function bootHasOrganizationId(): void
    {
        static::creating(function ($model) {
            if ($model->organization_id === null) {
                $org = app()->bound('current_organization')
                    ? app('current_organization')
                    : null;
                if ($org) {
                    $model->organization_id = $org->id;
                }
            }
        });
    }
}
