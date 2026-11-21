<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageBatchItem;
use App\Livewire\Admin\ImageBatchProgress;
use App\Livewire\Admin\ImageOptimizer;
use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Images\ActiveBatchExists;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ManualImageOptimization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1875 — optimisation MANUELLE des images depuis le SuperAdmin.
 *
 * Situation PROD reproduite : `QUEUE_CONNECTION=sync`, aucune connexion
 * dediee aux images, aucun worker. Le lot est enregistre sans conversion,
 * puis avance d'UNE image par requete HTTP.
 */
class TASK1875ManualImageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        config([
            'image_variants.queue_connection' => null,
            'queue.default' => 'sync',
            'image_variants.in_request_max_pixels' => 12_000_000,
        ]);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);
    }

    /** @return list<string> chemins des couvertures creees */
    private function covers(int $count, int $width = 400, int $height = 240): array
    {
        $paths = [];

        for ($i = 0; $i < $count; $i++) {
            $path = sprintf('loop-covers/t1875/cover-%02d.jpg', $i);
            Storage::disk('public')->put($path, UploadedFile::fake()->image('c.jpg', $width, $height)->getContent());
            Loop::factory()->create(['organization_id' => $this->org->id, 'cover_image_path' => $path]);
            $paths[] = $path;
        }

        return $paths;
    }

    private function manual(): ManualImageOptimization
    {
        return app(ManualImageOptimization::class);
    }

    private function step(ImageOptimizationBatch $batch, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)
            ->postJson(route('admin.images.manual.step', $batch));
    }

    private function processedCount(ImageOptimizationBatch $batch): int
    {
        return $batch->items()->whereNotIn('status', [ImageOptimizationBatchItem::STATUS_PENDING])->count();
    }

    // 1.
    public function test_a_superadmin_creates_a_manual_batch_under_sync_without_converting_anything(): void
    {
        Queue::fake();
        $this->covers(3);

        Livewire::actingAs($this->admin)
            ->test(ImageOptimizer::class)
            ->assertSeeHtml('data-marqueur="queue-non-configuree"')
            ->assertSeeHtml('data-marqueur="mode-manuel"')
            ->call('simulateManual')
            ->assertSet('manualSimulation.count', 3)
            ->call('launchManual')
            ->assertHasErrors('manualConfirmed')
            ->set('manualConfirmed', true)
            ->call('launchManual')
            ->assertHasNoErrors();

        $batch = ImageOptimizationBatch::query()->sole();
        $this->assertTrue($batch->isManual());
        $this->assertSame(3, $batch->total);
        $this->assertSame(3, $batch->items()->where('status', 'pending')->count());
        $this->assertSame(0, ImageVariant::count(), 'aucune conversion au lancement');
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
        Queue::assertNothingPushed();
    }

    // 2.
    public function test_an_ordinary_user_can_neither_create_nor_advance_a_batch(): void
    {
        $this->covers(2);
        $batch = $this->manual()->launch();
        $member = User::factory()->create(['is_admin' => false, 'organization_id' => $this->org->id]);

        $this->actingAs($member)->get(route('admin.images'))->assertForbidden();
        $this->step($batch, $member)->assertForbidden();

        auth()->logout();
        $this->postJson(route('admin.images.manual.step', $batch))->assertUnauthorized();

        $this->assertSame(0, $this->processedCount($batch));
        $this->assertSame(1, ImageOptimizationBatch::count(), 'aucun lot cree par le membre');
    }

    // 3.
    public function test_one_http_request_processes_at_most_one_image(): void
    {
        $this->covers(4);
        $batch = $this->manual()->launch();

        $this->step($batch)
            ->assertOk()
            ->assertJson(['state' => 'processed', 'item_status' => 'succeeded', 'continue' => true, 'done' => 1, 'total' => 4]);

        $this->assertSame(1, $this->processedCount($batch));
        $this->assertSame(3, ImageVariant::where('status', ImageVariant::STATUS_READY)->count(), 'une image, ses trois variantes');

        // Meme requete HTTP, second appel (Livewire ou le navigateur
        // regrouperaient deux actions) : refuse.
        $request = Request::create('/admin/images/manual/x/step', 'POST');
        $this->assertSame('processed', $this->manual()->step($batch->fresh(), $request)['state']);
        $this->assertSame('limit', $this->manual()->step($batch->fresh(), $request)['state']);
        $this->assertSame(2, $this->processedCount($batch));
    }

    // 4.
    public function test_two_simultaneous_requests_never_process_the_same_image(): void
    {
        $this->covers(3);
        $batch = $this->manual()->launch();

        // Une requete est en cours ailleurs : la seconde ne fait rien.
        $lock = Cache::lock(ManualImageOptimization::STEP_LOCK, 60);
        $this->assertTrue($lock->get());
        $this->step($batch)->assertOk()->assertJson(['state' => 'busy', 'continue' => true]);
        $this->assertSame(0, $this->processedCount($batch));
        $lock->release();

        // Une image deja reservee (traitement en cours) n'est pas reprise.
        $first = $batch->items()->orderBy('source_path')->first();
        $first->update(['status' => 'processing', 'started_at' => now()]);
        $this->step($batch)->assertJson(['state' => 'processed']);
        $this->assertSame('processing', $first->fresh()->status);
        $this->assertSame(0, $first->fresh()->attempts, 'jamais retentee par cette requete');

        // Reservee il y a plus de cinq minutes (requete tuee) : reprise, une fois.
        $first->update(['started_at' => now()->subSeconds(ManualImageOptimization::STALE_AFTER_SECONDS + 10)]);
        $this->step($batch)->assertJson(['state' => 'processed']);
        $this->step($batch)->assertJson(['state' => 'processed']);
        $this->assertSame(['succeeded'], $batch->items()->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $first->fresh()->attempts);
        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    // 5.
    public function test_a_manual_batch_never_exceeds_25_images(): void
    {
        $this->covers(30, 60, 40);

        $this->assertCount(25, $this->manual()->candidates(100));

        $batch = $this->manual()->launch(500);
        $this->assertSame(25, $batch->total);
        $this->assertSame(25, $batch->items()->count());
    }

    // 6.
    public function test_pause_stops_the_next_image_and_resume_continues(): void
    {
        $this->covers(3);
        $batch = $this->manual()->launch();
        $this->step($batch)->assertJson(['state' => 'processed']);

        Livewire::actingAs($this->admin)->test(ImageBatchProgress::class, ['batchId' => $batch->id])->call('pause');
        $this->assertTrue($batch->fresh()->isPaused());

        $this->step($batch)->assertOk()->assertJson(['state' => 'paused', 'continue' => false]);
        $this->assertSame(1, $this->processedCount($batch));

        Livewire::actingAs($this->admin)->test(ImageBatchProgress::class, ['batchId' => $batch->id])->call('resume')->assertHasNoErrors();
        $this->assertSame(ImageOptimizationBatch::STATUS_RUNNING, $batch->fresh()->status);

        $this->step($batch)->assertJson(['state' => 'processed']);
        $this->step($batch)->assertJson(['state' => 'processed', 'continue' => false, 'batch_status' => 'completed']);
        $this->assertSame(3, $this->processedCount($batch));
    }

    // 7.
    public function test_closing_the_browser_leaves_nothing_running(): void
    {
        Queue::fake();
        $this->covers(3);
        $batch = $this->manual()->launch();
        $this->step($batch)->assertJson(['state' => 'processed']);

        // Aucun job, aucune tache : sans requete du navigateur, rien n'avance.
        Queue::assertNothingPushed();
        $this->travel(10)->minutes();
        $this->assertSame(1, $this->processedCount($batch));

        // Un worker qui recevrait malgre tout une image d'un lot manuel n'y touche pas.
        $item = $batch->items()->where('status', 'pending')->first();
        app()->call([new ProcessImageBatchItem($item->id), 'handle']);
        $this->assertSame('pending', $item->fresh()->status);

        // Rouvrir la console ne relance rien sans clic.
        Livewire::actingAs($this->admin)->test(ImageOptimizer::class)->assertSet('autostartBatchId', null);
        Livewire::actingAs($this->admin)->test(ImageBatchProgress::class, ['batchId' => $batch->id])
            ->assertSet('autostart', false)
            ->assertSeeHtml('data-marqueur="reprendre-manuel"');
        $this->assertSame(1, $this->processedCount($batch));
    }

    // 8.
    public function test_an_image_too_large_for_a_web_request_is_skipped_with_its_reason(): void
    {
        config(['image_variants.in_request_max_pixels' => 50_000]);
        [$path] = $this->covers(1, 400, 300);
        $batch = $this->manual()->launch();

        $this->step($batch)->assertJson(['state' => 'processed', 'item_status' => 'skipped', 'reason' => 'deferred:400x300']);

        $this->assertSame(0, ImageVariant::count(), 'ni conversion ni inscription');
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);

        // Ecartee, elle ne bouche pas le lot suivant.
        $this->assertTrue($this->manual()->candidates()->doesntContain(fn ($source) => $source->path === $path));

        Livewire::actingAs($this->admin)->test(ImageBatchProgress::class, ['batchId' => $batch->id])
            ->assertSee(__('admin.image_optimizer.reason_deferred', ['size' => '400x300']));
    }

    // 9.
    public function test_originals_are_never_modified(): void
    {
        $paths = $this->covers(2);
        $before = array_map(fn ($p) => sha1(Storage::disk('public')->get($p)), $paths);

        $batch = $this->manual()->launch();
        $this->step($batch)->assertJson(['state' => 'processed']);
        $this->step($batch)->assertJson(['state' => 'processed']);

        $this->assertSame($before, array_map(fn ($p) => sha1(Storage::disk('public')->get($p)), $paths));
        $this->assertSame(6, ImageVariant::where('status', 'ready')->count());
        foreach (ImageVariant::pluck('path') as $variant) {
            $this->assertStringStartsWith('image-variants/', $variant);
        }
    }

    // 10.
    public function test_the_existing_asynchronous_mode_still_works_and_shares_the_single_active_batch_rule(): void
    {
        $this->covers(2);
        config(['image_variants.queue_connection' => 'database']);
        Queue::fake();

        $queued = app(ImageOptimizationBatches::class)->launch(null, null, 5);
        $this->assertSame(ImageOptimizationBatch::MODE_QUEUE, $queued->fresh()->mode);
        Queue::assertPushed(ProcessImageBatchItem::class, 2);

        // Un seul lot actif, tous modes confondus.
        $this->expectException(ActiveBatchExists::class);
        $this->manual()->launch();
    }

    public function test_the_step_endpoint_refuses_a_queue_batch(): void
    {
        $this->covers(1);
        config(['image_variants.queue_connection' => 'database']);
        Queue::fake();
        $queued = app(ImageOptimizationBatches::class)->launch(null, null, 5);

        $this->step($queued)->assertNotFound();
        $this->assertSame(0, $this->processedCount($queued));
    }
}
