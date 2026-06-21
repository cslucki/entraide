<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Illuminate\Support\Facades\Schema;

class DefaultOrganizationResolver
{
    public static function resolve(): ?Organization
    {
        if (! Schema::hasTable('organizations')) {
            return null;
        }

        return Organization::where('is_default', true)->first()
            ?? Organization::where('is_active', true)->orderBy('created_at')->first();
    }
}
