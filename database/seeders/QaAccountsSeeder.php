<?php

namespace Database\Seeders;

use App\Models\Community;
use App\Models\PointLedger;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class QaAccountsSeeder extends Seeder
{
    private string $password;

    private array $accounts;

    private array $qaCommunities = [
        ['name' => 'CPME', 'slug' => 'cpme'],
        ['name' => 'BNI', 'slug' => 'bni'],
        ['name' => '60 000 Rebonds', 'slug' => '60000rebonds'],
    ];

    public function __construct()
    {
        $this->password = Hash::make('password123');

        $this->accounts = [
            [
                'email' => 'qa-admin@bouclepro.local',
                'name' => 'QA Admin',
                'is_admin' => true,
                'community_slug' => 'cpme',
                'points' => 100,
            ],
            [
                'email' => 'qa-member1@bouclepro.local',
                'name' => 'QA Member 1',
                'is_admin' => false,
                'community_slug' => 'bni',
                'points' => 100,
            ],
            [
                'email' => 'qa-member2@bouclepro.local',
                'name' => 'QA Member 2',
                'is_admin' => false,
                'community_slug' => 'bni',
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

    private function ensureQaCommunitiesExist(): void
    {
        foreach ($this->qaCommunities as $data) {
            Community::firstOrCreate(['slug' => $data['slug']], $data);
        }
    }

    public function run(): void
    {
        $this->ensureQaCommunitiesExist();

        foreach ($this->accounts as $account) {
            $communityId = $account['community_slug']
                ? Community::where('slug', $account['community_slug'])->value('id')
                : null;

            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => $this->password,
                    'is_admin' => $account['is_admin'],
                    'is_available' => true,
                    'email_verified_at' => now(),
                    'points_balance' => $account['points'],
                    'community_id' => $communityId,
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
