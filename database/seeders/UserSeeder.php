<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $community = Organization::where('slug', 'cpme')->first()
            ?? DefaultOrganizationResolver::resolve();

        if (! $community) {
            throw new RuntimeException('UserSeeder requires an active organization.');
        }

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Utilisateur Test',
                'password' => Hash::make('password'),
                'points_balance' => 100,
                'is_available' => true,
                'is_admin' => true,
                'email_verified_at' => now(),
                'organization_id' => $community->id,
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
                'organization_id' => $community->id,
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
