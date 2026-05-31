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
            ['name' => 'CPME', 'slug' => 'cpme'],
            ['name' => 'BNI', 'slug' => 'bni'],
            ['name' => '60 000 Rebonds', 'slug' => '60000rebonds'],
        ];

        foreach ($organizations as $data) {
            Organization::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
