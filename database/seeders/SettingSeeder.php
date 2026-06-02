<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('is_default', true)->first()
            ?? Organization::where('slug', 'main')->first()
            ?? Organization::orderBy('created_at')->first();

        if (! $org) {
            return;
        }

        if (! $org->is_default) {
            $org->update(['is_default' => true]);
        }

        OrganizationSetting::set(null, 'platform_name', 'Entraide'); // Default config scope
        OrganizationSetting::set(null, 'platform_tagline', 'Échangez vos talents');
        OrganizationSetting::set(null, 'maintenance_mode', '0');
    }
}
