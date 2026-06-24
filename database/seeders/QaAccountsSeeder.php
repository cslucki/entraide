<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class QaAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('QaAccountsSeeder is deprecated. Use UserSeeder instead.');
        $this->call(UserSeeder::class);
    }
}
