<?php

namespace Database\Seeders;

use App\Models\Community;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CommunitySeeder extends Seeder
{
    public function run(): void
    {
        $communities = [
            ['name' => 'CPME', 'slug' => 'cpme'],
            ['name' => 'BNI', 'slug' => 'bni'],
            ['name' => '60 000 Rebonds', 'slug' => '60000rebonds'],
        ];

        foreach ($communities as $data) {
            Community::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
