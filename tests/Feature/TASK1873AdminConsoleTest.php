<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageVariants;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Images\ImageOptimizationControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1873 — `/admin/images` : SuperAdmin seul, simulation sans ecriture,
 * lot borne, confirme et MIS EN FILE, pause respectee.
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
        $this->actingAs($member)->post(route('admin.images.launch'), ['limit' => 1, 'confirm' => '1'])->assertForbidden();
        $this->actingAs($member)->post(route('admin.images.pause'))->assertForbidden();

        $this->assertFalse(app(ImageOptimizationControl::class)->paused());
    }

    public function test_the_console_shows_the_volumes_and_a_simulation_that_writes_nothing(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.images', ['simulate' => 1]))
            ->assertOk()
            ->assertSee(__('admin.image_optimizer.surface_loop_cover'))
            ->assertSee('data-marqueur="simulation"', false)
            ->assertSee('loop-covers/a1/cover.jpg');

        $this->assertSame(0, ImageVariant::count());
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
    }

    public function test_a_batch_requires_an_explicit_confirmation(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.images.launch'), ['limit' => 2])
            ->assertSessionHasErrors('confirm');

        Queue::assertNothingPushed();
    }

    public function test_a_confirmed_batch_is_queued_bounded_and_filtered_by_organization(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.images.launch'), ['limit' => 5, 'confirm' => '1', 'organization_id' => $this->orgB->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(ProcessImageVariants::class, 1);
        Queue::assertPushed(ProcessImageVariants::class, fn (ProcessImageVariants $job) => $job->queue === 'images'
            && $job->backfill === true
            && $job->organizationId === $this->orgB->id);
        $this->assertSame(0, ImageVariant::count(), 'rien n est traite dans la requete');
    }

    public function test_a_batch_above_the_ceiling_is_refused(): void
    {
        Queue::fake();

        // Queue `sync` en test, comme en local : plafond ramene a 5.
        $this->actingAs($this->superAdmin)
            ->post(route('admin.images.launch'), ['limit' => 6, 'confirm' => '1'])
            ->assertSessionHasErrors('limit');

        Queue::assertNothingPushed();
    }

    public function test_pause_blocks_a_batch_and_resume_lifts_it(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin)->post(route('admin.images.pause'))->assertRedirect(route('admin.images'));
        $this->assertTrue(app(ImageOptimizationControl::class)->paused());

        $this->actingAs($this->superAdmin)
            ->post(route('admin.images.launch'), ['limit' => 2, 'confirm' => '1'])
            ->assertSessionHas('error');
        Queue::assertNothingPushed();

        $this->actingAs($this->superAdmin)->post(route('admin.images.resume'));
        $this->assertFalse(app(ImageOptimizationControl::class)->paused());
    }

    public function test_an_executed_batch_appears_in_the_follow_up_with_its_measured_weights(): void
    {
        // Sans Queue::fake : `sync`, le lot s'execute (5 images au plus).
        $this->actingAs($this->superAdmin)
            ->post(route('admin.images.launch'), ['limit' => 1, 'confirm' => '1', 'organization_id' => $this->orgA->id])
            ->assertSessionHas('success');

        $this->assertSame(3, ImageVariant::where('status', 'ready')->count());

        $this->actingAs($this->superAdmin)
            ->get(route('admin.images'))
            ->assertOk()
            ->assertSee('data-marqueur="derniers-traitements"', false)
            ->assertSee('loop-covers/a1/cover.jpg')
            ->assertSee('Org A 1873');
    }
}
