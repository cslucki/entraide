<?php

namespace Database\Seeders;

use App\Models\Organization;
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

        $org->update([
            'platform_name' => 'Entraide',
            'platform_tagline' => 'Échangez vos talents',
            'maintenance_mode' => false,
            'loops_enabled' => true,
            'global_color_mode' => 'dark',
        ]);
    }
}
