<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\Task1848BenchmarkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1848 — le seeder de benchmark doit produire un jeu de donnees
 * STABLE (idempotent) et REEL (chemins de creation canoniques, pas
 * d'insertion brute) : ce test couvre uniquement ces deux garanties, pas
 * le benchmark lui-meme (scripts Node/bash, hors PHPUnit, voir
 * benchmarks/task-1848/README.md).
 */
class TASK1848BenchmarkSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_seeder_cree_l_organization_les_comptes_la_boucle_et_les_paires(): void
    {
        $this->seed(Task1848BenchmarkSeeder::class);

        $organization = Organization::where('slug', Task1848BenchmarkSeeder::ORG_SLUG)->first();
        $this->assertNotNull($organization);
        $this->assertFalse($organization->is_default);

        $this->assertSame(
            Task1848BenchmarkSeeder::USER_COUNT,
            User::where('organization_id', $organization->id)->count()
        );

        $loop = Loop::where('organization_id', $organization->id)->first();
        $this->assertNotNull($loop);
        $this->assertSame(Task1848BenchmarkSeeder::USER_COUNT, $loop->members()->count());

        app()->instance('current_organization', $organization);

        // 50 paires -> 50 conversations directes + 50 echanges d'entraide.
        $this->assertSame(100, Transaction::where('organization_id', $organization->id)->count());
        $this->assertSame(
            50,
            Transaction::where('organization_id', $organization->id)->where('points_proposed', 0)->count()
        );
        $this->assertSame(
            50,
            Transaction::where('organization_id', $organization->id)->where('points_proposed', 50)->count()
        );

        $transactionIds = Transaction::where('organization_id', $organization->id)->pluck('id');
        $this->assertSame(100, Message::whereIn('transaction_id', $transactionIds)->count());
    }

    public function test_relancer_le_seeder_ne_duplique_rien(): void
    {
        $this->seed(Task1848BenchmarkSeeder::class);
        $this->seed(Task1848BenchmarkSeeder::class);

        $organization = Organization::where('slug', Task1848BenchmarkSeeder::ORG_SLUG)->first();
        app()->instance('current_organization', $organization);

        $this->assertSame(
            Task1848BenchmarkSeeder::USER_COUNT,
            User::where('organization_id', $organization->id)->count()
        );
        $this->assertSame(100, Transaction::where('organization_id', $organization->id)->count());
    }

    public function test_le_mot_de_passe_connu_authentifie_reellement_un_compte(): void
    {
        $this->seed(Task1848BenchmarkSeeder::class);

        $user = User::where('email', 'benchmark1848.user001@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(Task1848BenchmarkSeeder::PASSWORD, $user->password)
        );
    }
}
