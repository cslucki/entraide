<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class QaAccountsSeeder extends Seeder
{
    private string $password;

    private array $accounts;

    private array $qaOrganizations = [
        ['name' => 'CPME', 'slug' => 'cpme'],
    ];

    public function __construct()
    {
        $this->password = Hash::make('password123');

        $this->accounts = [
            [
                'email' => 'qa-admin@bouclepro.local',
                'name' => 'QA Admin',
                'is_admin' => true,
                'community_slug' => null,
                'points' => 100,
            ],
            [
                'email' => 'qa-member1@bouclepro.local',
                'name' => 'QA Member 1',
                'is_admin' => false,
                'community_slug' => null,
                'points' => 100,
            ],
            [
                'email' => 'qa-member2@bouclepro.local',
                'name' => 'QA Member 2',
                'is_admin' => false,
                'community_slug' => null,
                'points' => 100,
            ],
            [
                'email' => 'qa-cpme1@bouclepro.local',
                'name' => 'QA CPME 1',
                'is_admin' => false,
                'community_slug' => 'cpme',
                'points' => 100,
            ],
            [
                'email' => 'qa-cpme2@bouclepro.local',
                'name' => 'QA CPME 2',
                'is_admin' => false,
                'community_slug' => 'cpme',
                'points' => 100,
            ],
        ];
    }

    private function ensureQaOrganizationsExist(): void
    {
        foreach ($this->qaOrganizations as $data) {
            Organization::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }

    public function run(): void
    {
        $this->ensureQaOrganizationsExist();

        foreach ($this->accounts as $account) {
            $communityId = $account['community_slug']
                ? Organization::where('slug', $account['community_slug'])->value('id')
                : DefaultOrganizationResolver::resolve()?->getKey();

            if (! $communityId) {
                throw new RuntimeException('QaAccountsSeeder requires an active organization for every QA account.');
            }

            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => $this->password,
                    'is_admin' => $account['is_admin'],
                    'is_available' => true,
                    'email_verified_at' => now(),
                    'points_balance' => $account['points'],
                    'organization_id' => $communityId,
                    'bio' => 'QA test account for Playwright and PHPUnit.',
                    'location' => 'Paris',
                    'phone' => '+33600000000',
                ]
            );

            if ($user->wasRecentlyCreated) {
                PointLedger::create([
                    'user_id' => $user->id,
                    'transaction_id' => null,
                    'delta' => $account['points'],
                    'reason' => 'welcome_bonus',
                ]);
            }
        }
    }
}
