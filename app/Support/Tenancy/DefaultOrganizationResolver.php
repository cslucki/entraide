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

        // Departage par `id` derriere l'horodatage : deux Organizations creees
        // dans la meme seconde laissaient PostgreSQL choisir au gre du plan —
        // stable sur SQLite, aleatoire en CI. Meme lecon que TASK-1117.
        return Organization::where('is_default', true)->orderBy('created_at')->orderBy('id')->first()
            ?? Organization::where('is_active', true)->orderBy('created_at')->orderBy('id')->first();
    }
}
