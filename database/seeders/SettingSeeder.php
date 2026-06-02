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

        OrganizationSetting::set($org->id, 'platform_name', 'Entraide');
        OrganizationSetting::set($org->id, 'platform_tagline', 'Échangez vos talents');
        OrganizationSetting::set($org->id, 'maintenance_mode', '0');

        $org->update(['is_default' => true]);
    }
}
