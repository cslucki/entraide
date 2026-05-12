<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;

class CurrentOrganization
{
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
