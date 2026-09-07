<?php

namespace Tests\Feature\Dossiers;

use App\Jobs\IndexDossierFileChunks;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1407 — toute indexation de FICHIER de Dossier part sur la queue dediee.
 *
 * Le defaut : `DossierFileIndexingDispatcher::dispatch()` n'appelait
 * `onQueue()` que si une queue explicite lui etait fournie. Seule la commande
 * `dossiers:index-files` en fournissait une. Les SIX chemins de
 * `DossierFileObserver` — created, contenu modifie, les DEUX cotes d'un
 * deplacement, deleted, restored — partaient donc sur `default`, en
 * contradiction avec la doctrine ecrite quatre lignes au-dessus de la
 * constante : un worker dedie ne les aurait jamais vus.
 *
 * Ces gardes mesurent la QUEUE REELLE du job pousse (`Queue::fake()` intercepte
 * au niveau du QueueManager et expose donc la file de destination, ce que
 * `Bus::fake()` ne fait pas), jamais la presence de `DEDICATED_QUEUE` dans la
 * source.
 *
 * HORS SCOPE, et volontairement non teste ici : les Articles
 * (`IndexDossierArticleChunks`), qui continuent d'alimenter `default`. Aucun
 * job historique n'est reveille, purge ni draine par cette TASK.
 */
class TASK1407DossierFileQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // ── 1. Aucune queue explicite ───────────────────────────────────────────

    public function test_a_dispatch_without_an_explicit_queue_goes_to_the_dedicated_queue(): void
    {
        $organization = Organization::factory()->create();
        $dossier = Dossier::factory()->create(['organization_id' => $organization->id]);
        Queue::fake();

        app(DossierFileIndexingDispatcher::class)->dispatch(
            (string) $organization->id,
            (string) $dossier->id,
            (string) fake()->uuid(),
        );

        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
        $this->assertNoFileJobOnDefault();
    }

    /**
     * Le trou qu'une valeur par defaut de PARAMETRE n'aurait pas bouche :
     * `dispatchForFiles()` retransmet son propre `$queue` EXPLICITEMENT, y
     * compris quand il vaut null — et un defaut de parametre ne s'applique
     * qu'a un argument OMIS.
     */
    public function test_dispatch_for_files_without_a_queue_also_goes_to_the_dedicated_queue(): void
    {
        $organization = Organization::factory()->create();
        $file = $this->textFile($organization);
        Queue::fake();

        $count = app(DossierFileIndexingDispatcher::class)->dispatchForFiles([$file]);

        $this->assertSame(1, $count);
        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
        $this->assertNoFileJobOnDefault();
    }

    // ── 2. Queue explicite preservee ────────────────────────────────────────

    public function test_an_explicit_queue_is_preserved_untouched(): void
    {
        $organization = Organization::factory()->create();
        $dossier = Dossier::factory()->create(['organization_id' => $organization->id]);
        Queue::fake();

        app(DossierFileIndexingDispatcher::class)->dispatch(
            (string) $organization->id,
            (string) $dossier->id,
            (string) fake()->uuid(),
            'custom-indexing',
        );

        Queue::assertPushedOn('custom-indexing', IndexDossierFileChunks::class);
        Queue::assertNotPushed(
            IndexDossierFileChunks::class,
            fn (IndexDossierFileChunks $job, ?string $queue): bool => $queue !== 'custom-indexing',
        );
    }

    // ── 3. Les chemins de l'Observer ────────────────────────────────────────

    public function test_creating_a_file_pushes_on_the_dedicated_queue(): void
    {
        $organization = Organization::factory()->create();
        $dossier = Dossier::factory()->create(['organization_id' => $organization->id]);
        Queue::fake();

        DossierFile::factory()->create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'path' => 'dossiers/'.fake()->uuid().'.txt',
        ]);

        Queue::assertPushed(IndexDossierFileChunks::class, 1);
        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
        $this->assertNoFileJobOnDefault();
    }

    /**
     * Le chemin le plus facile a manquer : lors d'un deplacement, l'Observer
     * appelle `dispatch()` DIRECTEMENT pour l'ANCIEN Dossier (il n'a plus de
     * modele qui le porte), puis `dispatchForFile()` pour le nouveau. Les deux
     * cotes doivent partir sur la queue dediee.
     */
    public function test_moving_a_file_pushes_both_sides_on_the_dedicated_queue(): void
    {
        $organization = Organization::factory()->create();
        $ancien = Dossier::factory()->create(['organization_id' => $organization->id]);
        $nouveau = Dossier::factory()->create(['organization_id' => $organization->id]);

        $file = DossierFile::factory()->create([
            'organization_id' => $organization->id,
            'dossier_id' => $ancien->id,
            'path' => 'dossiers/'.fake()->uuid().'.txt',
        ]);

        Queue::fake();
        $file->update(['dossier_id' => $nouveau->id]);

        Queue::assertPushed(IndexDossierFileChunks::class, 2);
        $this->assertNoFileJobOnDefault();

        // Les DEUX cotes, nommement — et chacun sur la queue dediee.
        foreach ([(string) $ancien->id, (string) $nouveau->id] as $dossierId) {
            Queue::assertPushed(
                IndexDossierFileChunks::class,
                fn (IndexDossierFileChunks $job, ?string $queue): bool => $job->dossierId === $dossierId
                    && $queue === DossierFileIndexingDispatcher::DEDICATED_QUEUE,
            );
        }
    }

    public function test_changing_the_content_pushes_on_the_dedicated_queue(): void
    {
        $organization = Organization::factory()->create();
        $file = $this->textFile($organization);
        Queue::fake();

        $file->update(['checksum_sha256' => hash('sha256', 'contenu modifie')]);

        Queue::assertPushed(IndexDossierFileChunks::class, 1);
        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
        $this->assertNoFileJobOnDefault();
    }

    public function test_deleting_and_restoring_a_file_push_on_the_dedicated_queue(): void
    {
        $organization = Organization::factory()->create();
        $file = $this->textFile($organization);

        Queue::fake();
        $file->delete();
        Queue::assertPushed(IndexDossierFileChunks::class, 1);
        $this->assertNoFileJobOnDefault();

        Queue::fake();
        $file->restore();
        Queue::assertPushed(IndexDossierFileChunks::class, 1);
        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
        $this->assertNoFileJobOnDefault();
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, un Observer debranche ferait passer TOUTES les gardes
     * « aucun job sur default » ci-dessus : zero job pousse, zero job sur
     * default. Ce temoin exige qu'un job existe REELLEMENT, et que
     * l'assertion de queue sache distinguer deux files.
     */
    public function test_the_probe_really_pushes_a_job_and_can_tell_two_queues_apart(): void
    {
        $organization = Organization::factory()->create();
        Queue::fake();

        $this->textFile($organization);

        Queue::assertPushed(IndexDossierFileChunks::class, 1);
        Queue::assertNotPushed(
            IndexDossierFileChunks::class,
            fn (IndexDossierFileChunks $job, ?string $queue): bool => $queue === 'une-file-qui-n-existe-pas',
        );
        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Un job pousse SANS `onQueue()` se presente au callback avec `null`, et
     * jamais avec la chaine `'default'` — mesure faite en sabotant le routage,
     * ou une closure typee `string $queue` levait un TypeError au lieu de
     * rendre un verdict. Les closures de ce fichier acceptent donc `?string`,
     * et cette garde couvre les trois formes : c'est elle qui porte la
     * promesse de la TASK, elle ne doit dependre d'aucune subtilite du fake.
     */
    private function assertNoFileJobOnDefault(): void
    {
        Queue::assertNotPushed(
            IndexDossierFileChunks::class,
            fn (IndexDossierFileChunks $job, ?string $queue): bool => $queue === 'default'
                || $queue === null
                || $queue === '',
        );
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
