<?php

namespace Tests\Feature\Dossiers;

use App\Jobs\IndexDossierArticleChunks;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DossierArticleIndexingAfterCommitTest extends TestCase
{
    public function refreshDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException('DossierArticleIndexingAfterCommitTest requires safe-test sqlite :memory:.');
        }

        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function test_database_queue_inserts_job_only_after_root_commit(): void
    {
        $this->useDatabaseQueue();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('jobs')->count());

        DB::beginTransaction();

        try {
            app(DossierArticleIndexingDispatcher::class)->dispatch(
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '33333333-3333-4333-8333-333333333333',
            );

            $this->assertSame(0, DB::table('jobs')->count());
        } finally {
            DB::commit();
        }

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertQueuedDatabasePayloadContains(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
        );
    }

    public function test_database_queue_discards_after_commit_job_on_rollback(): void
    {
        $this->useDatabaseQueue();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('jobs')->count());

        DB::beginTransaction();

        try {
            app(DossierArticleIndexingDispatcher::class)->dispatch(
                '44444444-4444-4444-8444-444444444444',
                '55555555-5555-4555-8555-555555555555',
                '66666666-6666-4666-8666-666666666666',
            );

            $this->assertSame(0, DB::table('jobs')->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_database_queue_inserts_immediately_without_open_transaction(): void
    {
        $this->useDatabaseQueue();
        $this->assertSame(0, DB::transactionLevel());

        app(DossierArticleIndexingDispatcher::class)->dispatch(
            '77777777-7777-4777-8777-777777777777',
            '88888888-8888-4888-8888-888888888888',
            '99999999-9999-4999-8999-999999999999',
        );

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertQueuedDatabasePayloadContains(
            '77777777-7777-4777-8777-777777777777',
            '88888888-8888-4888-8888-888888888888',
            '99999999-9999-4999-8999-999999999999',
        );
    }

    private function useDatabaseQueue(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', config('database.default'));
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('queue.connections.database.after_commit', false);
    }

    private function assertQueuedDatabasePayloadContains(string $organizationId, string $dossierId, string $blogPostId): void
    {
        $payload = json_decode((string) DB::table('jobs')->first()->payload, true);

        $this->assertSame(IndexDossierArticleChunks::class, $payload['displayName']);
        $this->assertStringContainsString($organizationId, $payload['data']['command']);
        $this->assertStringContainsString($dossierId, $payload['data']['command']);
        $this->assertStringContainsString($blogPostId, $payload['data']['command']);
    }
}
