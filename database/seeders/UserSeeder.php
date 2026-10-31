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
        $mainOrg = Organization::where('slug', 'main')->first()
            ?? DefaultOrganizationResolver::resolve();
        $launchpalsOrg = Organization::where('slug', 'launchpals')->first();

        if (! $mainOrg) {
            throw new RuntimeException('UserSeeder requires "main" organization.');
        }

        $accounts = [
            [
                'email' => 'admin@bouclepro.test',
                'name' => 'Demo Admin',
                'is_admin' => true,
                'org' => $mainOrg,
                'bio' => 'Platform administrator and demo user.',
                'location' => 'Paris',
                'phone' => '+33600000001',
            ],
            [
                'email' => 'main.member1@bouclepro.test',
                'name' => 'Demo Main Member 1',
                'is_admin' => false,
                'org' => $mainOrg,
                'bio' => 'Demo member of the main organization.',
                'location' => 'Lyon',
                'phone' => '+33600000002',
            ],
            [
                'email' => 'main.member2@bouclepro.test',
                'name' => 'Demo Main Member 2',
                'is_admin' => false,
                'org' => $mainOrg,
                'bio' => 'Demo member of the main organization.',
                'location' => 'Marseille',
                'phone' => '+33600000003',
            ],
            [
                'email' => 'launchpals.member1@bouclepro.test',
                'name' => 'Demo LaunchPals Member 1',
                'is_admin' => true,
                'org' => $launchpalsOrg,
                'bio' => 'LaunchPals community lead and demo user.',
                'location' => 'Bordeaux',
                'phone' => '+33600000004',
            ],
            [
                'email' => 'launchpals.member2@bouclepro.test',
                'name' => 'Demo LaunchPals Member 2',
                'is_admin' => false,
                'org' => $launchpalsOrg,
                'bio' => 'Demo member of LaunchPals.',
                'location' => 'Nantes',
                'phone' => '+33600000005',
            ],
        ];

        foreach ($accounts as $data) {
            $org = $data['org'];
            if (! $org) {
                continue;
            }

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'points_balance' => 100,
                    'is_available' => true,
                    'is_admin' => $data['is_admin'],
                    'email_verified_at' => now(),
                    // TASK-1723 — un compte QA est APPROUVE d'office.
                    //
                    // TASK-1714 a pose `approved_at` et la garde
                    // `RequireApprovedAccount`. Les trois Organizations de ce
                    // depot ont `requires_account_approval = true` : depuis,
                    // un compte seede s'authentifiait mais toute page protegee
                    // le renvoyait sur `/approbation-en-attente`.
                    //
                    // Mesure, pas deduction : `POST /login` rendait bien 302
                    // vers `/admin/dashboard`, puis `/dashboard` arrivait sur
                    // `/approbation-en-attente`. Le README avertissait deja
                    // qu'« un seeder qui sort en succes ne garantit pas un
                    // compte connectable » — c'etait precisement ce cas.
                    //
                    // Un compte de demonstration n'a personne pour l'approuver :
                    // le poser approuve est la seule forme qui tienne.
                    'approved_at' => now(),
                    'organization_id' => $org->id,
                    'bio' => $data['bio'],
                    'location' => $data['location'],
                    'phone' => $data['phone'],
                ]
            );

            if ($user->wasRecentlyCreated) {
                PointLedger::create([
                    'user_id' => $user->id,
                    'transaction_id' => null,
                    'delta' => 100,
                    'organization_id' => $user->organization_id,
                    'reason' => 'welcome_bonus',
                ]);
            }
        }
    }
}
