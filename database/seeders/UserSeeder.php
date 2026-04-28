<?php

namespace Database\Seeders;

use App\Models\PointLedger;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Utilisateur Test',
                'password' => Hash::make('password'),
                'points_balance' => 100,
                'is_available' => true,
                'email_verified_at' => now(),
            ]
        );

        if ($user->wasRecentlyCreated) {
            PointLedger::create([
                'user_id' => $user->id,
                'transaction_id' => null,
                'delta' => 100,
                'reason' => 'welcome_bonus',
            ]);
        }

        $user2 = User::firstOrCreate(
            ['email' => 'alice@example.com'],
            [
                'name' => 'Alice Martin',
                'password' => Hash::make('password'),
                'points_balance' => 100,
                'is_available' => true,
                'email_verified_at' => now(),
            ]
        );

        if ($user2->wasRecentlyCreated) {
            PointLedger::create([
                'user_id' => $user2->id,
                'transaction_id' => null,
                'delta' => 100,
                'reason' => 'welcome_bonus',
            ]);
        }
    }
}
