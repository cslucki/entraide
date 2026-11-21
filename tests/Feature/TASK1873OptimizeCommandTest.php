<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageVariants;
use App\Models\BlogPost;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Images\ImageOptimizationControl;
use App\Support\Images\ImageOptimizationInventory;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1873 — `images:optimize` : simulation sans ecriture, lots bornes,
 * reprise sans retraitement, filtre d'Organization, pause.
 */
class TASK1873OptimizeCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1873']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1873']);

        foreach (['a1', 'a2', 'a3'] as $name) {
            $this->cover($this->orgA, $name);
        }
        $this->cover($this->orgB, 'b1');
    }

    private function cover(Organization $org, string $name): Loop
    {
        $path = "loop-covers/{$name}/cover.jpg";
        Storage::disk('public')->put($path, UploadedFile::fake()->image('c.jpg', 1200, 700)->getContent());

        return Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => $path]);
    }

    public function test_the_default_mode_is_a_simulation_that_writes_nothing(): void
    {
        $this->artisan('images:optimize', ['--surface' => 'loop_cover'])
            ->expectsOutputToContain('SIMULATION')
            ->expectsOutputToContain('loop-covers/a1/cover.jpg')
            ->assertSuccessful();

        $this->assertSame(0, ImageVariant::count());
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
    }

    public function test_a_bounded_batch_processes_exactly_its_limit_then_resumes_without_reprocessing(): void
    {
        $this->artisan('images:optimize', ['--surface' => 'loop_cover', '--limit' => 2, '--execute' => true])->assertSuccessful();
        $this->assertSame(2, ImageVariant::distinct()->count('source_path'));

        $firstWave = ImageVariant::pluck('processed_at', 'id');
        $this->travel(1)->minutes();

        $this->artisan('images:optimize', ['--surface' => 'loop_cover', '--limit' => 10, '--execute' => true])->assertSuccessful();
        $this->assertSame(4, ImageVariant::distinct()->count('source_path'));

        foreach ($firstWave as $id => $processedAt) {
            $this->assertEquals($processedAt, ImageVariant::find($id)->processed_at, 'une image deja traitee n est pas retraitee');
        }

        $this->artisan('images:optimize', ['--surface' => 'loop_cover'])
            ->expectsOutputToContain('Rien a traiter')
            ->assertSuccessful();
    }

    public function test_the_organization_filter_never_touches_another_tenant(): void
    {
        $this->artisan('images:optimize', ['--organization' => 'org-b-1873', '--execute' => true])->assertSuccessful();

        $this->assertSame([$this->orgB->id], ImageVariant::distinct()->pluck('organization_id')->all());
        $this->assertSame(['loop-covers/b1/cover.jpg'], ImageVariant::distinct()->pluck('source_path')->all());
    }

    public function test_pause_refuses_a_batch_and_resume_lifts_it(): void
    {
        $this->artisan('images:optimize', ['--pause' => true])->assertSuccessful();
        $this->assertTrue(app(ImageOptimizationControl::class)->paused());

        $this->artisan('images:optimize', ['--execute' => true])->assertFailed();
        $this->assertSame(0, ImageVariant::count());

        $this->artisan('images:optimize', ['--resume' => true])->assertSuccessful();
        $this->artisan('images:optimize', ['--execute' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame(1, ImageVariant::distinct()->count('source_path'));
    }

    public function test_the_queue_mode_dispatches_backfill_jobs_on_the_images_queue(): void
    {
        Queue::fake();

        $this->artisan('images:optimize', ['--execute' => true, '--queue' => true, '--limit' => 3])->assertSuccessful();

        Queue::assertPushed(ProcessImageVariants::class, 3);
        Queue::assertPushed(ProcessImageVariants::class, fn (ProcessImageVariants $job) => $job->queue === 'images' && $job->backfill === true);
    }

    public function test_shared_bank_avatars_deleted_posts_and_external_urls_are_never_candidates(): void
    {
        $key = ManifestAvatarBank::keys('fictional-avatars-v1')[0];
        User::factory()->create(['avatar' => ManifestAvatarBank::publishedPath('fictional-avatars-v1', $key)]);
        User::factory()->create(['avatar' => 'https://exemple.test/a.png']);
        $post = BlogPost::create([
            'user_id' => User::factory()->create()->id, 'organization_id' => $this->orgA->id,
            'title' => 'Supprime', 'content' => '<p>x</p>', 'status' => 'draft', 'image' => 'blog/supprime.jpg',
        ]);
        $post->delete();

        $summary = app(ImageOptimizationInventory::class)->summary();

        $this->assertSame(0, $summary['user_avatar']['total']);
        $this->assertSame(0, $summary['blog_cover']['total']);
        $this->assertSame(4, $summary['loop_cover']['total']);
    }

    public function test_status_reports_pending_then_ready_with_measured_weights(): void
    {
        $this->artisan('images:optimize', ['--execute' => true, '--surface' => 'loop_cover', '--organization' => $this->orgB->id])->assertSuccessful();

        $summary = app(ImageOptimizationInventory::class)->summary();
        $this->assertSame(['total' => 4, 'pending' => 3, 'ready' => 1], array_intersect_key($summary['loop_cover'], array_flip(['total', 'pending', 'ready'])));
        $this->assertGreaterThan(0, $summary['loop_cover']['source_bytes']);
        $this->assertGreaterThan(0, $summary['loop_cover']['variant_bytes']);

        $this->artisan('images:optimize', ['--status' => true])->expectsOutputToContain('loop_cover')->assertSuccessful();
    }
}
