<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = [
            ['name' => 'Main', 'slug' => 'main'],
            ['name' => 'LaunchPals', 'slug' => 'launchpals'],
        ];

        foreach ($organizations as $data) {
            Organization::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
