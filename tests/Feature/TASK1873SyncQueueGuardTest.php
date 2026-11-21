<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageBatchItem;
use App\Jobs\ProcessImageVariants;
use App\Livewire\Admin\ImageBatchProgress;
use App\Livewire\Admin\ImageOptimizer;
use App\Models\ImageOptimizationBatch;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ImageQueue;
use App\Support\Images\ImageQueueNotConfigured;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1873 — garde contre l'execution SYNCHRONE des images.
 *
 * Situation PROD mesuree le 21/11/2026 : `QUEUE_CONNECTION=sync`, aucune
 * connexion dediee. Reproduite ici : `image_variants.queue_connection` vide,
 * connexion par defaut `sync`.
 */
class TASK1873SyncQueueGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        config(['image_variants.queue_connection' => null, 'queue.default' => 'sync']);

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'members_can_create_loops' => true]);
        foreach (['s1', 's2'] as $name) {
            $path = "loop-covers/{$name}/cover.jpg";
            Storage::disk('public')->put($path, UploadedFile::fake()->image('c.jpg', 1200, 700)->getContent());
            Loop::factory()->create(['organization_id' => $this->org->id, 'cover_image_path' => $path]);
        }
    }

    private function useAsyncQueue(): void
    {
        config(['image_variants.queue_connection' => 'database']);
    }

    public function test_the_resolved_connection_is_judged_on_its_driver(): void
    {
        $queue = app(ImageQueue::class);

        $this->assertSame('sync', $queue->connection());
        $this->assertFalse($queue->isAsync());

        foreach (['deferred', 'background', 'null'] as $connection) {
            config(['image_variants.queue_connection' => $connection]);
            $this->assertFalse($queue->isAsync(), "{$connection} reste dans le processus web ou perd le job");
        }

        config(['image_variants.queue_connection' => 'connexion-inconnue']);
        $this->assertFalse($queue->isAsync());

        $this->useAsyncQueue();
        $this->assertTrue($queue->isAsync());
    }

    public function test_under_sync_the_business_layer_refuses_to_launch_a_batch(): void
    {
        Queue::fake();

        try {
            app(ImageOptimizationBatches::class)->launch(null, null, 5);
            $this->fail('un lot ne doit pas partir sur une connexion synchrone');
        } catch (ImageQueueNotConfigured $e) {
            $this->assertSame('sync', $e->connectionName);
        }

        $this->assertSame(0, ImageOptimizationBatch::count());
        $this->assertSame(0, ImageVariant::count());
        Queue::assertNothingPushed();
    }

    public function test_under_sync_the_console_explains_and_launches_nothing_in_the_web_request(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ImageOptimizer::class)
            ->assertSee(__('admin.image_optimizer.queue_not_configured'))
            ->assertSeeHtml('data-marqueur="queue-non-configuree"')
            ->set('limit', 5)
            ->set('confirmed', true)
            ->call('optimize')
            ->assertHasErrors('launch');

        $this->assertSame(0, ImageOptimizationBatch::count());
        $this->assertSame(0, ImageVariant::count(), 'aucune conversion dans la requete web');
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
    }

    public function test_under_sync_a_paused_batch_cannot_be_resumed_from_the_console(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Queue::fake();
        $this->useAsyncQueue();
        $batch = app(ImageOptimizationBatches::class)->launch(null, null, 5);
        app(ImageOptimizationBatches::class)->pause($batch);

        // La configuration perd sa connexion asynchrone (variable retiree).
        config(['image_variants.queue_connection' => null]);
        Queue::fake();

        Livewire::test(ImageBatchProgress::class, ['batchId' => $batch->id])
            ->call('resume')
            ->assertHasErrors('retry');

        $this->assertSame('paused', $batch->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertSame(0, ImageVariant::count());
    }

    public function test_under_sync_an_upload_keeps_its_original_works_and_reports_the_problem(): void
    {
        Log::spy();
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        app()->instance('current_organization', $this->org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Boucle sous sync',
            'description' => 'Une description.',
            'access_mode' => 'open',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
        ])->assertRedirect();

        $loop = Loop::where('name', 'Boucle sous sync')->firstOrFail();
        Storage::disk('public')->assertExists($loop->cover_image_path);
        $this->assertSame(Storage::disk('public')->url($loop->cover_image_path), $loop->cover_image_url, 'l original est servi');
        $this->assertSame(0, ImageVariant::count(), 'aucune derivation dans la requete d upload');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'queue images non asynchrone')
                && ($context['path'] ?? null) === $loop->cover_image_path)
            ->once();
    }

    public function test_under_sync_a_locked_item_is_left_pending_without_any_redispatch_loop(): void
    {
        Queue::fake();
        $this->useAsyncQueue();
        $batch = app(ImageOptimizationBatches::class)->launch('loop_cover', null, 1);
        $item = $batch->items()->firstOrFail();

        config(['image_variants.queue_connection' => null]);
        Queue::fake();

        // Un autre processus tient l'image.
        $lock = Cache::lock('image-variants:'.sha1('public|'.$item->source_path), 60);
        $this->assertTrue($lock->get());

        app()->call([new ProcessImageBatchItem($item->id), 'handle']);

        $this->assertSame('pending', $item->fresh()->status);
        Queue::assertNothingPushed();
        $lock->release();
    }

    public function test_under_sync_an_explicit_cli_run_still_works_outside_any_web_request(): void
    {
        $this->artisan('images:optimize', ['--execute' => true, '--queue' => true, '--limit' => 1])
            ->expectsOutputToContain("n'est pas configure")
            ->assertFailed();
        $this->assertSame(0, ImageOptimizationBatch::count());

        $this->artisan('images:optimize', ['--execute' => true, '--limit' => 1, '--surface' => 'loop_cover'])
            ->assertSuccessful();
        $this->assertSame(3, ImageVariant::where('status', 'ready')->count(), 'une image, trois variantes, en CLI');
    }

    public function test_the_queue_default_is_never_targeted(): void
    {
        Queue::fake();
        $this->useAsyncQueue();

        ProcessImageVariants::forUpload('loop_cover', 'loop-covers/s1/cover.jpg', $this->org->id);
        app(ImageOptimizationBatches::class)->launch('loop_cover', null, 5);

        Queue::assertPushed(ProcessImageVariants::class, fn ($job) => $job->queue === 'images');
        Queue::assertPushed(ProcessImageBatchItem::class, fn ($job) => $job->queue === 'images');
        Queue::assertNotPushed(ProcessImageVariants::class, fn ($job) => in_array($job->queue, [null, 'default'], true));
        Queue::assertNotPushed(ProcessImageBatchItem::class, fn ($job) => in_array($job->queue, [null, 'default'], true));
    }
}
