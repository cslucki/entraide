<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;

class CurrentOrganization
{
    /**
     * Resolve the current tenant.
     *
     * Resolution order (canonical):
     * 1. current_organization — source of truth (Organization = Tenant)
     * 2. current_community — legacy fallback ONLY (temporary, see TASK-090)
     *
     * The current_community fallback is kept for backward compatibility
     * during the Community → Organization migration. It MUST NOT be
     * reinforced as a normal source. Scheduled for removal post-migration.
     *
     * Neither bound → returns null → BelongsToTenantScope applies
     * whereRaw('0 = 1') → fail-closed safety.
     */
    public static function get(): ?Model
    {
        if (app()->bound('current_organization')) {
            return app('current_organization');
        }

        if (app()->bound('current_community')) {
            return app('current_community');
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
