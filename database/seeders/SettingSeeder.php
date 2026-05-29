<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('platform_name', 'Entraide');
        Setting::set('platform_tagline', 'Échangez vos talents');
        Setting::set('maintenance_mode', '0');

        // Default Organization — résolue par ResolveUrlOrganization
        // fallback #2 après static::$defaultOrganizationId.
        $default = Organization::where('slug', 'main')->first()
            ?? Organization::orderBy('created_at')->first();
        if ($default) {
            Setting::set('default_organization_id', $default->id);
        }
    }
}
