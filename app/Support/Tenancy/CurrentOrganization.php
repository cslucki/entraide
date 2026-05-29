<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;

class CurrentOrganization
{
    /**
     * Resolve the current tenant (canonical).
     *
     * current_organization is the sole source of truth for runtime tenant context.
     * Not bound → returns null → BelongsToTenantScope applies
     * whereRaw('0 = 1') → fail-closed safety.
     */
    public static function get(): ?Model
    {
        if (app()->bound('current_organization')) {
            return app('current_organization');
        }

        return null;
    }

    public static function id(): ?string
    {
        return static::get()?->id;
    }

    public static function resolved(): ?Model
    {
        return static::get();
    }
}
