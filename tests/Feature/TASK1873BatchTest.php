<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageBatchItem;
use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Support\Images\ActiveBatchExists;
use App\Support\Images\ImageOptimizationBatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1873 (phase 2) — les lots suivis : asynchrones, progression exacte
 * en base, pause/reprise sans perte ni doublon, relance des echecs.
 */
class TASK1873BatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->org = Organization::factory()->create();
    }

    private function covers(int $count, ?Organization $org = null, int $width = 320, int $height = 180): void
    {
        $org ??= $this->org;
        $content = UploadedFile::fake()->image('c.jpg', $width, $height)->getContent();

        for ($i = 1; $i <= $count; $i++) {
            $path = sprintf('loop-covers/%s-%03d/cover.jpg', substr($org->id, 0, 8), $i);
            Storage::disk('public')->put($path, $content);
            Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => $path]);
        }
    }

    private function batches(): ImageOptimizationBatches
    {
        return app(ImageOptimizationBatches::class);
    }

    /** Execute les jobs mis en file, comme le ferait le worker. */
    private function work(?int $max = null): int
    {
        $jobs = Queue::pushed(ProcessImageBatchItem::class);
        $ran = 0;

        foreach ($jobs as $job) {
            if ($max !== null && $ran >= $max) {
                break;
            }
            app()->call([$job, 'handle']);
            $ran++;
        }

        return $ran;
    }

    public function test_launching_records_the_batch_and_queues_one_job_per_image_without_converting_anything(): void
    {
        $this->covers(5);

        $batch = $this->batches()->launch('loop_cover', null, 5);

        $this->assertSame(ImageOptimizationBatch::STATUS_PENDING, $batch->status);
        $this->assertSame(5, $batch->total);
        $this->assertSame(5, $batch->items()->where('status', 'pending')->count());
        Queue::assertPushed(ProcessImageBatchItem::class, 5);
        Queue::assertPushed(ProcessImageBatchItem::class, fn (ProcessImageBatchItem $job) => $job->queue === 'images');
        Queue::assertNotPushed(ProcessImageBatchItem::class, fn (ProcessImageBatchItem $job) => in_array($job->queue, [null, 'default'], true));
        $this->assertSame(0, ImageVariant::count(), 'aucune conversion au lancement');
    }

    public function test_progress_is_exact_and_one_image_with_three_variants_counts_once(): void
    {
        $this->covers(5);
        $batch = $this->batches()->launch('loop_cover', null, 5);

        $this->work(2);
        $progress = $this->batches()->progress($batch->fresh());

        $this->assertSame(2, $progress['done']);
        $this->assertSame(2, $progress['succeeded']);
        $this->assertSame(3, $progress['pending']);
        $this->assertSame(40, $progress['percent']);
        $this->assertSame(6, ImageVariant::count(), 'deux images, trois variantes chacune');
        $this->assertSame(ImageOptimizationBatch::STATUS_RUNNING, $batch->fresh()->status);

        $this->work();
        $final = $this->batches()->progress($batch->fresh());
        $this->assertSame(100, $final['percent']);
        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertGreaterThan(0, $final['served_source_bytes']);
        $this->assertGreaterThan(0, $final['served_bytes']);
        $this->assertLessThan($final['served_source_bytes'], $final['served_bytes']);
    }

    public function test_a_batch_of_fifty_images_completes(): void
    {
        $this->covers(50, null, 64, 40);

        $batch = $this->batches()->launch(null, null, 50);
        $this->assertSame(50, $batch->total);

        $this->work();

        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(50, $this->batches()->progress($batch->fresh())['succeeded']);
    }

    public function test_pause_suspends_the_next_images_and_resume_continues_the_same_batch_without_reprocessing(): void
    {
        $this->covers(5);
        $batch = $this->batches()->launch('loop_cover', null, 5);
        $this->work(2);

        $this->batches()->pause($batch);
        // Le worker continue de vider la file : les jobs restants ne font rien.
        $this->work();
        $this->assertSame(3, $batch->items()->where('status', 'pending')->count());
        $this->assertSame(ImageOptimizationBatch::STATUS_PAUSED, $batch->fresh()->status);

        Queue::fake();
        $this->batches()->resume($batch->fresh());
        Queue::assertPushed(ProcessImageBatchItem::class, 3);
        $this->work();

        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(5, $batch->items()->where('status', 'succeeded')->count());
        $this->assertSame(0, $batch->items()->where('attempts', '>', 1)->count(), 'aucune image traitee deux fois');
        $this->assertSame(1, ImageOptimizationBatch::count(), 'la reprise continue le MEME lot');
    }

    public function test_a_batch_paused_before_its_first_image_still_reports_a_duration(): void
    {
        $this->covers(2);
        $batch = $this->batches()->launch('loop_cover', null, 2);
        $this->batches()->pause($batch);

        Queue::fake();
        $this->batches()->resume($batch->fresh());
        $this->work();

        $this->assertNotNull($batch->fresh()->started_at);
        $this->assertNotNull($this->batches()->progress($batch->fresh())['duration_seconds']);
    }

    public function test_a_second_launch_is_refused_and_a_duplicated_job_does_nothing(): void
    {
        $this->covers(3);
        $batch = $this->batches()->launch('loop_cover', null, 3);

        try {
            $this->batches()->launch('loop_cover', null, 3);
            $this->fail('un second lot ne doit pas partir tant que le premier est actif');
        } catch (ActiveBatchExists $e) {
            $this->assertSame($batch->id, $e->batch?->id);
        }

        $item = $batch->items()->firstOrFail();
        app()->call([new ProcessImageBatchItem($item->id), 'handle']);
        app()->call([new ProcessImageBatchItem($item->id), 'handle']);

        $this->assertSame(1, $item->fresh()->attempts);
        $this->assertSame(1, ImageOptimizationBatch::count());
    }

    public function test_a_failed_job_is_reported_and_can_be_retried(): void
    {
        $this->covers(2);
        $batch = $this->batches()->launch('loop_cover', null, 2);
        [$first, $second] = $batch->items()->orderBy('source_path')->get()->all();

        app()->call([new ProcessImageBatchItem($first->id), 'handle']);
        (new ProcessImageBatchItem($second->id))->failed(new RuntimeException('worker tue'));

        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED_WITH_ERRORS, $batch->fresh()->status);
        $this->assertStringContainsString('worker tue', (string) $second->fresh()->error);

        Queue::fake();
        $this->assertSame(1, $this->batches()->retryFailed($batch->fresh()));
        Queue::assertPushed(ProcessImageBatchItem::class, 1);
        $this->work();

        $this->assertSame('succeeded', $second->fresh()->status);
        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_a_missing_original_is_counted_apart_from_technical_failures(): void
    {
        $this->covers(2);
        $batch = $this->batches()->launch('loop_cover', null, 2);
        Storage::disk('public')->delete($batch->items()->orderBy('source_path')->value('source_path'));

        $this->work();

        $progress = $this->batches()->progress($batch->fresh());
        $this->assertSame(1, $progress['missing']);
        $this->assertSame(0, $progress['failed']);
        $this->assertSame(1, $progress['succeeded']);
        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_a_batch_filtered_on_one_organization_never_touches_another(): void
    {
        $other = Organization::factory()->create();
        $this->covers(2);
        $this->covers(2, $other);

        $batch = $this->batches()->launch(null, $other->id, 100);

        $this->assertSame(2, $batch->total);
        $this->assertSame([$other->id], $batch->items()->distinct()->pluck('organization_id')->all());
    }

    public function test_a_lost_worker_is_detected_and_its_image_recovered_on_resume(): void
    {
        $this->covers(1);
        $batch = $this->batches()->launch('loop_cover', null, 1);
        $item = $batch->items()->firstOrFail();

        // Worker tue en plein traitement : l'image reste `processing`.
        $item->update(['status' => ImageOptimizationBatchItem::STATUS_PROCESSING, 'started_at' => now()->subMinutes(10)]);
        $this->batches()->pause($batch);

        Queue::fake();
        $this->batches()->resume($batch->fresh());
        $this->work();

        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    public function test_waiting_for_worker_is_reported_when_nothing_consumes_the_queue(): void
    {
        $this->covers(1);
        $batch = $this->batches()->launch('loop_cover', null, 1);

        $this->travel(30)->seconds();

        $this->assertTrue($this->batches()->progress($batch->fresh())['waiting_for_worker']);
    }
}
