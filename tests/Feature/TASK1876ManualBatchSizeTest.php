<?php

namespace Tests\Feature;

use App\Livewire\Admin\ImageOptimizer;
use App\Models\ImageOptimizationBatch;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1876 — taille du lot manuel : 1, 5 ou 25 images, validee cote serveur,
 * pour une recette PROD progressive sans Pause manuelle.
 */
class TASK1876ManualBatchSizeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['image_variants.queue_connection' => null, 'queue.default' => 'sync']);

        $org = Organization::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true, 'organization_id' => $org->id]);

        for ($i = 0; $i < 8; $i++) {
            $path = sprintf('loop-covers/t1876/cover-%02d.jpg', $i);
            Storage::disk('public')->put($path, UploadedFile::fake()->image('c.jpg', 120, 80)->getContent());
            Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => $path]);
        }
    }

    public function test_the_default_size_is_25_and_the_selector_offers_1_5_25(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImageOptimizer::class)
            ->assertSet('manualLimit', 25)
            ->assertSeeHtml('data-marqueur="taille-lot-manuel"')
            ->assertSeeHtml('wire:model.live="manualLimit" value="1"')
            ->assertSeeHtml('wire:model.live="manualLimit" value="5"')
            ->assertSeeHtml('wire:model.live="manualLimit" value="25"');
    }

    public function test_simulation_and_launch_respect_the_selected_size(): void
    {
        $seen = [];

        foreach ([1 => 1, 5 => 5] as $limit => $expected) {
            Livewire::actingAs($this->admin)
                ->test(ImageOptimizer::class)
                ->set('manualLimit', $limit)
                ->call('simulateManual')
                ->assertSet('manualSimulation.count', $expected)
                ->set('manualConfirmed', true)
                ->call('launchManual')
                ->assertHasNoErrors();

            // Pas `latest()` : deux lots crees dans la meme seconde ont le
            // meme `created_at`, l'ordre ne serait pas deterministe (rouge CI).
            $batch = ImageOptimizationBatch::query()->whereNotIn('id', $seen)->sole();
            $seen[] = $batch->id;
            $this->assertTrue($batch->isManual());
            $this->assertSame($expected, $batch->total);
            $this->assertSame($expected, $batch->items()->count());

            // Le lot traite d'une image va a son terme en UNE requete.
            for ($n = 0; $n < $expected; $n++) {
                $this->actingAs($this->admin)
                    ->postJson(route('admin.images.manual.step', $batch))
                    ->assertJson(['state' => 'processed', 'done' => $n + 1]);
            }
            $this->assertSame(ImageOptimizationBatch::STATUS_COMPLETED, $batch->fresh()->status);
        }
    }

    public function test_a_size_outside_1_5_25_is_refused_by_the_server(): void
    {
        foreach ([7, 50, 0, -1] as $forged) {
            Livewire::actingAs($this->admin)
                ->test(ImageOptimizer::class)
                ->set('manualLimit', $forged)
                ->call('simulateManual')
                ->assertHasErrors('manualLimit')
                ->assertSet('manualSimulation', null)
                ->set('manualConfirmed', true)
                ->call('launchManual')
                ->assertHasErrors('manualLimit');
        }

        $this->assertSame(0, ImageOptimizationBatch::count());
    }

    public function test_changing_the_size_invalidates_the_simulation_and_the_confirmation(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImageOptimizer::class)
            ->call('simulateManual')
            ->assertSet('manualSimulation.count', 8)
            ->set('manualConfirmed', true)
            ->set('manualLimit', 1)
            ->assertSet('manualSimulation', null)
            ->assertSet('manualConfirmed', false)
            ->call('launchManual')
            ->assertHasErrors('manualConfirmed');

        $this->assertSame(0, ImageOptimizationBatch::count());
    }
}
