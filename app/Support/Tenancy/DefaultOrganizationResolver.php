<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class DefaultOrganizationResolver
{
    public static function resolve(): ?Organization
    {
        if (! Schema::hasTable('organizations')) {
            return null;
        }

        $defaultId = Schema::hasTable('settings')
            ? Setting::get('default_organization_id')
            : null;

        if ($defaultId) {
            $organization = Organization::whereKey($defaultId)
                ->where('is_active', true)
                ->first();

            if ($organization) {
                return $organization;
            }
        }

        $organization = Organization::where('slug', 'main')
            ->where('is_active', true)
            ->first();

        if ($organization) {
            return $organization;
        }

        return Organization::where('is_active', true)
            ->orderBy('created_at')
            ->first();
    }
}
