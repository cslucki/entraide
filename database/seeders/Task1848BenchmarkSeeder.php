<?php

namespace Database\Seeders;

use App\Models\Loop;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * TASK-1848 — jeu de donnees du benchmark temps reel, COMPTES DE TEST
 * uniquement (aucune donnee personnelle, aucun secret).
 *
 * Idempotent (slug et emails fixes, `firstOrCreate` partout) : relancable
 * sans effet sur un jumeau deja seme. Mot de passe CONNU et FIXE
 * (`Hash::make('password')`, deja le defaut de `UserFactory`) — necessaire
 * pour qu'un script de simulation authentifie reellement chaque compte via
 * le flux de login HTTP standard, jamais pour un usage humain.
 *
 * Chemins de creation REELS (`LoopService`, `Transaction::create()` —
 * meme idiome que `MessageController::showWithUser()`), jamais une
 * insertion brute.
 */
class Task1848BenchmarkSeeder extends Seeder
{
    public const ORG_SLUG = 'benchmark-task-1848';

    public const USER_COUNT = 100;

    public const PASSWORD = 'password';

    public function run(): void
    {
        $organization = Organization::firstOrCreate(
            ['slug' => self::ORG_SLUG],
            [
                'name' => 'Benchmark TASK-1848 (comptes de test)',
                'is_active' => true,
                'is_default' => false,
            ]
        );

        $users = [];
        for ($i = 1; $i <= self::USER_COUNT; $i++) {
            $users[] = User::firstOrCreate(
                ['email' => sprintf('benchmark1848.user%03d@example.test', $i)],
                [
                    'organization_id' => $organization->id,
                    'name' => sprintf('Benchmark%03d', $i),
                    'first_name' => 'Bench',
                    'email_verified_at' => now(),
                    'approved_at' => now(),
                    'password' => Hash::make(self::PASSWORD),
                    'points_balance' => 100,
                    'is_available' => true,
                    'is_admin' => false,
                ]
            );
        }

        // `Transaction` porte `BelongsToOrganizationScope` : sans ce
        // contexte, toute lecture echoue fail-closed (TASK-1847) et les
        // `firstOrCreate()` plus bas ne verraient JAMAIS les lignes
        // existantes — recreant un doublon a CHAQUE relance. Un seeder
        // `artisan db:seed` ne passe par aucun middleware HTTP qui le
        // lierait normalement.
        app()->instance('current_organization', $organization);

        $owner = $users[0];
        $loopService = app(LoopService::class);

        $loop = Loop::where('organization_id', $organization->id)
            ->where('name', 'Benchmark TASK-1848')
            ->first();

        if (! $loop) {
            $loop = $loopService->createLoopForOrg(
                $owner,
                $organization->id,
                'Benchmark TASK-1848',
                'Boucle de benchmark temps reel — comptes de test.',
                'private'
            );

            foreach (array_slice($users, 1) as $member) {
                $loopService->addMember($loop, $member);
            }
        }

        // Conversations directes et echanges d'entraide par paires
        // consecutives (1<->2, 3<->4, ...) — assez pour que chaque membre
        // simule dans S1/S2/S3 ait un fil REEL a consulter.
        for ($i = 0; $i + 1 < count($users); $i += 2) {
            $buyer = $users[$i];
            $seller = $users[$i + 1];

            $direct = Transaction::firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'service_id' => null,
                    'request_id' => null,
                    'points_proposed' => 0,
                ],
                ['status' => 'pending']
            );

            if ($direct->wasRecentlyCreated) {
                Message::create([
                    'transaction_id' => $direct->id,
                    'sender_id' => null,
                    'body' => 'Conversation de benchmark demarree.',
                    'type' => 'system',
                    'organization_id' => $organization->id,
                ]);
            }

            $entraide = Transaction::firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'points_proposed' => 50,
                ],
                ['status' => 'accepted', 'points_agreed' => 50]
            );

            if ($entraide->wasRecentlyCreated) {
                Message::create([
                    'transaction_id' => $entraide->id,
                    'sender_id' => $buyer->id,
                    'body' => 'Echange de benchmark.',
                    'type' => 'user',
                    'organization_id' => $organization->id,
                ]);
            }
        }

        $this->exportFixtures($organization, $loop, $users);

        $this->command?->info(sprintf(
            'TASK-1848 seed OK : organization=%s users=%d loop=%s mot_de_passe=%s',
            $organization->slug,
            count($users),
            $loop->id,
            self::PASSWORD
        ));
    }

    /**
     * Export LOCAL, hors depot (chemin fourni par `TASK1848_FIXTURES_PATH`,
     * sinon un chemin par defaut sous `storage/`) : les scripts Node/bash du
     * benchmark en ont besoin pour retrouver les identifiants REELS (UUID
     * des transactions par paire, id de la Boucle) sans jamais les deviner
     * ni les coder en dur — reproductible (ce seeder EST le committe), pas
     * les UUID eux-memes qui different a chaque jumeau.
     *
     * @param  array<int, User>  $users
     */
    private function exportFixtures(Organization $organization, Loop $loop, array $users): void
    {
        $pairs = [];
        for ($i = 0; $i + 1 < count($users); $i += 2) {
            $buyer = $users[$i];
            $seller = $users[$i + 1];

            $direct = Transaction::where('organization_id', $organization->id)
                ->where('buyer_id', $buyer->id)->where('seller_id', $seller->id)
                ->where('points_proposed', 0)->first();

            $entraide = Transaction::where('organization_id', $organization->id)
                ->where('buyer_id', $buyer->id)->where('seller_id', $seller->id)
                ->where('points_proposed', 50)->first();

            $pairs[] = [
                'buyer_index' => $i,
                'seller_index' => $i + 1,
                'direct_transaction_id' => $direct?->id,
                'entraide_transaction_id' => $entraide?->id,
            ];
        }

        $fixtures = [
            'organization_id' => $organization->id,
            'organization_slug' => $organization->slug,
            'loop_id' => $loop->id,
            'password' => self::PASSWORD,
            'users' => array_map(
                fn (User $u, int $i) => ['index' => $i, 'id' => $u->id, 'email' => $u->email],
                $users,
                array_keys($users)
            ),
            'pairs' => $pairs,
        ];

        $path = env('TASK1848_FIXTURES_PATH') ?: storage_path('app/benchmarks/task-1848-fixtures.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->command?->info("TASK-1848 fixtures ecrites : {$path}");
    }
}
