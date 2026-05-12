<?php

namespace App\Models\Traits;

trait HasOrganizationId
{
    public static function bootHasOrganizationId(): void
    {
        static::creating(function ($model) {
            $model->syncOrganizationId();
        });

        static::updating(function ($model) {
            if ($model->isDirty('community_id')) {
                $model->syncOrganizationId();
            }
        });
    }

    public function syncOrganizationId(): void
    {
        $this->organization_id = $this->community_id;
    }
}
