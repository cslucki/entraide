<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageBatchItem;
use App\Livewire\Admin\ImageBatchProgress;
use App\Livewire\Admin\ImageOptimizer;
use App\Models\ImageOptimizationBatch;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1873 (phase 2) — `/admin/images` : SuperAdmin seul, lancement
 * confirme et asynchrone, progression lue en base (rechargement compris).
 */
class TASK1873AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        // TASK-1873 — les lots et les uploads exigent une connexion de queue
        // ASYNCHRONE (garde `ImageQueue`) ; `Queue::fake()` intercepte.
        config(['image_variants.queue_connection' => 'database']);

        $this->orgA = Organization::factory()->create(['name' => 'Org A 1873']);
        $this->orgB = Organization::factory()->create(['name' => 'Org B 1873']);
        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);

        foreach ([[$this->orgA, 'a1'], [$this->orgA, 'a2'], [$this->orgB, 'b1']] as [$org, $name]) {
            $path = "loop-covers/{$name}/cover.jpg";
            Storage::disk('public')->put($path, UploadedFile::fake()->image('c.jpg', 1200, 700)->getContent());
            Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => $path]);
        }
    }

    public function test_only_the_super_admin_reaches_the_console(): void
    {
        $this->get(route('admin.images'))->assertRedirect(route('login'));

        $member = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);
        $this->actingAs($member)->get(route('admin.images'))->assertForbidden();

        $this->actingAs($this->superAdmin)->get(route('admin.images'))
            ->assertOk()
            ->assertSee('data-marqueur="synthese"', false)
            ->assertSeeLivewire(ImageOptimizer::class);
    }

    public function test_the_components_refuse_a_non_admin_even_when_mounted_directly(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);
        $this->actingAs($member);

        Livewire::test(ImageOptimizer::class)->assertForbidden();
    }

    public function test_simulation_writes_nothing(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ImageOptimizer::class)
            ->set('limit', 5)
            ->call('simulate')
            ->assertSet('simulation.count', 3)
            ->assertSee('loop-covers/a1/cover.jpg');

        $this->assertSame(0, ImageOptimizationBatch::count());
        $this->assertSame(0, ImageVariant::count());
        Queue::assertNothingPushed();
    }

    public function test_optimize_requires_confirmation_then_returns_at_once_with_a_queued_batch(): void
    {
        $this->actingAs($this->superAdmin);

        $component = Livewire::test(ImageOptimizer::class)
            ->set('limit', 5)
            ->call('optimize')
            ->assertHasErrors('confirmed');
        $this->assertSame(0, ImageOptimizationBatch::count());

        $component->set('confirmed', true)->call('optimize')->assertHasNoErrors();

        $batch = ImageOptimizationBatch::firstOrFail();
        $this->assertSame(3, $batch->total);
        Queue::assertPushed(ProcessImageBatchItem::class, 3);
        $this->assertSame(0, ImageVariant::count(), 'aucune conversion dans la requete');
        $component->assertSeeLivewire(ImageBatchProgress::class);
    }

    public function test_an_out_of_list_batch_size_is_refused(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ImageOptimizer::class)
            ->set('limit', 7)
            ->set('confirmed', true)
            ->call('optimize')
            ->assertHasErrors('limit');

        Queue::assertNothingPushed();
    }

    public function test_a_double_click_does_not_launch_a_second_batch(): void
    {
        $this->actingAs($this->superAdmin);

        $component = Livewire::test(ImageOptimizer::class)->set('limit', 5)->set('confirmed', true)->call('optimize');
        $component->set('confirmed', true)->call('optimize')->assertHasErrors('launch');

        $this->assertSame(1, ImageOptimizationBatch::count());
        Queue::assertPushed(ProcessImageBatchItem::class, 3);
    }

    public function test_the_organization_filter_reaches_the_batch(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ImageOptimizer::class)
            ->set('organizationId', $this->orgB->id)
            ->set('limit', 25)
            ->set('confirmed', true)
            ->call('optimize');

        $batch = ImageOptimizationBatch::firstOrFail();
        $this->assertSame($this->orgB->id, $batch->organization_id);
        $this->assertSame(['loop-covers/b1/cover.jpg'], $batch->items()->pluck('source_path')->all());
    }

    public function test_progress_survives_a_reload_and_polling_stops_when_done(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(ImageOptimizer::class)->set('limit', 5)->set('confirmed', true)->call('optimize');
        $batch = ImageOptimizationBatch::firstOrFail();

        // Le worker traite une image.
        app()->call([Queue::pushed(ProcessImageBatchItem::class)->first(), 'handle']);

        // « Rechargement » : un composant neuf lit la meme progression en base.
        Livewire::test(ImageBatchProgress::class, ['batchId' => $batch->id])
            ->assertSee('1 images sur 3')
            ->assertSeeHtml('wire:poll.2500ms');

        foreach (Queue::pushed(ProcessImageBatchItem::class)->slice(1) as $job) {
            app()->call([$job, 'handle']);
        }

        Livewire::test(ImageBatchProgress::class, ['batchId' => $batch->id])
            ->assertSee('3 images sur 3')
            ->assertSee('data-marqueur="bilan"', false)
            ->assertDontSeeHtml('wire:poll.2500ms');
    }

    public function test_pause_and_resume_from_the_progress_card(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(ImageOptimizer::class)->set('limit', 5)->set('confirmed', true)->call('optimize');
        $batch = ImageOptimizationBatch::firstOrFail();

        Livewire::test(ImageBatchProgress::class, ['batchId' => $batch->id])
            ->call('pause')
            ->assertSee(__('admin.image_optimizer.resume'));
        $this->assertSame('paused', $batch->fresh()->status);

        Livewire::test(ImageBatchProgress::class, ['batchId' => $batch->id])->call('resume');
        $this->assertSame('running', $batch->fresh()->status);
    }
}
