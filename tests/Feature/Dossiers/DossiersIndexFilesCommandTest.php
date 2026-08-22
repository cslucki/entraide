<?php

namespace Tests\Feature\Dossiers;

use App\Jobs\IndexDossierFileChunks;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Dossiers\DossierSemanticSearchGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1268 : commande `dossiers:index-files` — selection bornee + dispatch
 * sur une queue dediee, jamais `default`. Aucun provider n'est jamais
 * appele ici : la commande ne fait que selectionner et dispatcher.
 */
class DossiersIndexFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_gate_is_open_for_main_slug_and_closed_for_other_organizations(): void
    {
        $main = Organization::factory()->create(['slug' => 'main']);
        $other = Organization::factory()->create(['slug' => 'other-org']);

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', []);
        config()->set('ai.dossiers.semantic_search.organization_slugs', ['main']);

        $gate = app(DossierSemanticSearchGate::class);

        $this->assertTrue($gate->isEnabledFor($main->id));
        $this->assertFalse($gate->isEnabledFor($other->id));
        $this->assertFalse($gate->isEnabledFor('00000000-0000-0000-0000-000000000000'));
    }

    public function test_it_refuses_an_unknown_organization_without_dispatching(): void
    {
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'does-not-exist'])
            ->expectsOutputToContain('Organization inconnue')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_it_refuses_the_default_queue(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);
        $this->textFile($organization);
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--queue' => 'default'])
            ->expectsOutputToContain('jamais sur `default`')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_dry_run_counts_and_lists_without_dispatching_anything(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);
        $file = $this->textFile($organization, 'note.txt');
        $this->textFile($organization, 'readme.md', 'text/markdown');
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--dry-run' => true])
            ->expectsOutputToContain('Fichiers éligibles : 2')
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain($file->id)
            ->expectsOutputToContain('Rien n’a été dispatché')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_limit_is_respected_in_dry_run_and_in_dispatch(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);

        foreach (range(1, 5) as $index) {
            $this->textFile($organization, "lot-{$index}.txt");
        }

        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--dry-run' => true, '--limit' => 2])
            ->expectsOutputToContain('Fichiers éligibles : 5 — retenus (--limit=2) : 2')
            ->assertExitCode(0);

        Queue::assertNothingPushed();

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--limit' => 3])
            ->expectsOutputToContain('3 job(s) d’indexation planifié(s)')
            ->assertExitCode(0);

        Queue::assertPushed(IndexDossierFileChunks::class, 3);
    }

    public function test_it_rejects_an_invalid_limit(): void
    {
        Organization::factory()->create(['slug' => 'main']);
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--limit' => '0'])
            ->assertExitCode(1);

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--limit' => 'abc'])
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_only_supported_mime_types_of_the_requested_organization_are_selected(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);
        $other = Organization::factory()->create(['slug' => 'other-org']);

        $plain = $this->textFile($organization, 'plain.txt');
        $markdown = $this->textFile($organization, 'doc.md', 'text/markdown');
        $pdf = DossierFile::factory()->inDossier()->create([
            'organization_id' => $organization->id,
            'mime_type' => 'application/pdf',
        ]);
        $deleted = $this->textFile($organization, 'deleted.txt');
        $deleted->delete();
        $orphan = DossierFile::factory()->create([
            'organization_id' => $organization->id,
            'dossier_id' => null,
            'mime_type' => 'text/plain',
        ]);
        $foreign = $this->textFile($other, 'foreign.txt');

        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => $organization->id])
            ->expectsOutputToContain('Fichiers éligibles : 2')
            ->assertExitCode(0);

        Queue::assertPushed(IndexDossierFileChunks::class, 2);

        foreach ([$plain, $markdown] as $expected) {
            Queue::assertPushed(
                IndexDossierFileChunks::class,
                fn (IndexDossierFileChunks $job): bool => $job->fileId === $expected->id
                    && $job->organizationId === $organization->id
                    && $job->dossierId === $expected->dossier_id,
            );
        }

        foreach ([$pdf, $deleted, $orphan, $foreign] as $excluded) {
            Queue::assertNotPushed(
                IndexDossierFileChunks::class,
                fn (IndexDossierFileChunks $job): bool => $job->fileId === $excluded->id,
            );
        }
    }

    public function test_jobs_go_to_the_dedicated_queue_and_never_to_default(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);
        $this->textFile($organization, 'a.txt');
        $this->textFile($organization, 'b.txt');
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'main'])
            ->expectsOutputToContain('Queue : '.DossierFileIndexingDispatcher::DEDICATED_QUEUE)
            ->assertExitCode(0);

        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
        Queue::assertPushed(IndexDossierFileChunks::class, 2);
        Queue::assertNotPushed(
            IndexDossierFileChunks::class,
            fn (IndexDossierFileChunks $job, string $queue): bool => $queue === 'default' || $queue === null || $queue === '',
        );
    }

    public function test_an_explicit_custom_queue_is_honoured(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);
        $this->textFile($organization, 'a.txt');
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => 'main', '--queue' => 'custom-indexing'])
            ->assertExitCode(0);

        Queue::assertPushedOn('custom-indexing', IndexDossierFileChunks::class);
        Queue::assertNotPushed(
            IndexDossierFileChunks::class,
            fn (IndexDossierFileChunks $job, string $queue): bool => $queue !== 'custom-indexing',
        );
    }

    public function test_dispatch_for_files_skips_entries_without_dossier_or_organization(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main']);
        $valid = $this->textFile($organization, 'a.txt');
        Queue::fake();

        $count = app(DossierFileIndexingDispatcher::class)->dispatchForFiles([
            $valid,
            ['organization_id' => $organization->id, 'dossier_id' => '', 'id' => 'x'],
            ['organization_id' => '', 'dossier_id' => $valid->dossier_id, 'id' => 'y'],
        ], 'custom-indexing');

        $this->assertSame(1, $count);
        Queue::assertPushed(IndexDossierFileChunks::class, 1);
        Queue::assertPushedOn('custom-indexing', IndexDossierFileChunks::class);
    }

    private function textFile(Organization $organization, string $name = 'note.txt', string $mime = 'text/plain'): DossierFile
    {
        $dossier = Dossier::factory()->create(['organization_id' => $organization->id]);

        return DossierFile::factory()->create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mime,
            'path' => 'dossiers/'.fake()->uuid().'.txt',
        ]);
    }
}
