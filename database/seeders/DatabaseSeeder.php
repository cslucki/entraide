<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OrganizationSeeder::class,
            CategorySeeder::class,
            SkillSeeder::class,
            PointGuidelineSeeder::class,
            UserSeeder::class,
            QaAccountsSeeder::class,
            BadgeSeeder::class,
            SettingSeeder::class,
            BackfillUsersOrganizationSeeder::class,
            EmailTemplateSeeder::class,
        ]);
    }
}
