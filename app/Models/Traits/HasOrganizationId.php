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
            if ($model->isDirty('organization_id') || $model->isDirty('community_id')) {
                $model->syncOrganizationId();
            }
        });
    }

    public function syncOrganizationId(): void
    {
        if ($this->exists) {
            if ($this->isDirty('organization_id')) {
                $this->community_id = $this->organization_id;
                return;
            }

            if ($this->isDirty('community_id')) {
                $this->organization_id = $this->community_id;
                return;
            }

            return;
        }

        if ($this->organization_id !== null) {
            $this->community_id = $this->organization_id;
            return;
        }

        if ($this->community_id !== null) {
            $this->organization_id = $this->community_id;
            return;
        }
    }
}
