<?php

namespace Tests\Feature;

use App\Livewire\Admin\ImageOptimizer;
use App\Models\User;
use App\Support\Images\ImageWorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1873 — la console dit si un worker consomme la queue `images`.
 */
class TASK1873WorkerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ImageWorkerHeartbeat::resetThrottle();
        config(['image_variants.queue_connection' => 'database']);
    }

    public function test_a_worker_loop_on_the_images_queue_records_a_sign_of_life(): void
    {
        $heartbeat = app(ImageWorkerHeartbeat::class);
        $this->assertFalse($heartbeat->isAlive());

        event(new Looping('database', 'images'));

        $this->assertTrue($heartbeat->isAlive());
    }

    public function test_the_production_worker_list_counts_too(): void
    {
        event(new Looping('database', 'dossier-files-indexing,dossier-articles-indexing,images'));

        $this->assertTrue(app(ImageWorkerHeartbeat::class)->isAlive());
    }

    public function test_a_worker_on_other_queues_never_counts(): void
    {
        event(new Looping('database', 'default'));
        event(new Looping('database', 'dossier-files-indexing'));

        $this->assertNull(app(ImageWorkerHeartbeat::class)->lastSeen());
    }

    public function test_a_sync_worker_with_images_in_its_list_never_counts(): void
    {
        // Le cas PROD du 21/11 : `queue:work` planifie sur `sync`.
        config(['image_variants.queue_connection' => null, 'queue.default' => 'sync']);

        event(new Looping('sync', 'dossier-files-indexing,images'));

        $this->assertNull(app(ImageWorkerHeartbeat::class)->lastSeen());
    }

    public function test_a_worker_on_another_connection_never_counts(): void
    {
        event(new Looping('redis', 'images'));

        $this->assertNull(app(ImageWorkerHeartbeat::class)->lastSeen());
    }

    public function test_a_silent_worker_becomes_unavailable_after_ninety_seconds(): void
    {
        event(new Looping('database', 'images'));

        $this->travel(91)->seconds();

        $this->assertFalse(app(ImageWorkerHeartbeat::class)->isAlive());
    }

    public function test_the_console_shows_the_worker_state(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ImageOptimizer::class)
            ->assertSeeHtml('data-worker-alive="0"')
            ->assertSee(__('admin.image_optimizer.worker_down'));

        event(new Looping('database', 'images'));

        Livewire::test(ImageOptimizer::class)
            ->assertSeeHtml('data-worker-alive="1"')
            ->assertSee(__('admin.image_optimizer.worker_alive'));
    }
}
