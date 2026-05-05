<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CommunitySeeder::class,
            CategorySeeder::class,
            SkillSeeder::class,
            PointGuidelineSeeder::class,
            UserSeeder::class,
            BadgeSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
