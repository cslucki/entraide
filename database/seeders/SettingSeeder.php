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

        $default = Organization::where('is_default', true)->first()
            ?? Organization::where('slug', 'main')->first()
            ?? Organization::orderBy('created_at')->first();
        if ($default) {
            $default->update(['is_default' => true]);
        }
    }
}
