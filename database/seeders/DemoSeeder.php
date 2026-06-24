<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('DemoSeeder is deprecated. Use DashboardDemoSeeder via db:seed instead.');

        $this->call(DashboardDemoSeeder::class);

        $users = User::where('email', 'like', '%@bouclepro.test')->get();

        $this->command->info('Comptes demo disponibles (mdp: password) :');
        foreach ($users as $user) {
            $role = $user->is_admin ? 'admin' : 'member';
            $this->command->info("  - {$user->email} ({$user->name}) [{$role}]");
        }
    }
}
