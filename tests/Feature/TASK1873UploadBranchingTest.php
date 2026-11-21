<?php

namespace Tests\Feature;

use App\Jobs\GenerateServiceThumbnail;
use App\Jobs\ProcessImageVariants;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

/**
 * TASK-1873 — chaque surface d'upload met en file SA derivation, sur la
 * queue `images`, avec SON Organization ; et l'affichage sert la variante.
 */
class TASK1873UploadBranchingTest extends TestCase
{
    use RefreshDatabase;
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // Connexion EXPLICITE (et non celle du `.env` du poste) : les uploads
        // ne sont mis en file que sur une connexion asynchrone (garde
        // `ImageQueue`). Les tests interceptent par `Queue::fake()` et
        // executent eux-memes le job quand ils en ont besoin.
        config(['image_variants.queue_connection' => 'database']);
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    private function assertQueuedFor(string $surface, ?string $organizationId): void
    {
        Queue::assertPushedOn(
            ProcessImageVariants::QUEUE,
            ProcessImageVariants::class,
            fn (ProcessImageVariants $job) => $job->surface === $surface
                && $job->organizationId === $organizationId
                && $job->backfill === false
                && $job->force === true,
        );

        Queue::assertPushed(ProcessImageVariants::class, fn (ProcessImageVariants $job) => $job->queue === 'images'
            && $job->afterCommit === true);
    }

    public function test_a_loop_cover_upload_is_queued_on_the_images_queue_never_default(): void
    {
        Queue::fake();
        $org = Organization::factory()->create(['loops_enabled' => true, 'members_can_create_loops' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Boucle T1873',
            'description' => 'Une description.',
            'access_mode' => 'open',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
        ])->assertRedirect();

        $this->assertQueuedFor('loop_cover', $org->id);
        Queue::assertNotPushed(ProcessImageVariants::class, fn (ProcessImageVariants $job) => $job->queue === 'default' || $job->queue === null);
    }

    public function test_a_profile_avatar_upload_is_queued(): void
    {
        Queue::fake();
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $org);
        $user = User::factory()->complete()->create(['organization_id' => $org->id, 'email_verified_at' => now()]);

        $this->actingAs($user)->put(route('profile.update'), [
            'first_name' => 'Camille',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'bio' => str_repeat('Une presentation de profil assez longue. ', 4),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+33600000000',
            'slogan' => 'Un slogan.',
            'avatar' => UploadedFile::fake()->image('moi.jpg', 600, 600),
        ]);

        $this->assertQueuedFor('user_avatar', $org->id);
    }

    public function test_an_admin_avatar_upload_is_queued(): void
    {
        Queue::fake();
        $org = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($admin)->post(route('admin.users.avatar.update', $member), [
            'avatar' => UploadedFile::fake()->image('photo.jpg', 900, 900),
        ]);

        $this->assertNotNull($member->fresh()->avatar);
        $this->assertQueuedFor('user_avatar', $org->id);
    }

    public function test_an_organization_logo_upload_is_queued(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.organizations.update', $org), [
            'name' => $org->name,
            'slug' => $org->slug,
            'welcome_points' => 100,
            'platform_name' => 'Test',
            'global_color_mode' => 'dark',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ]);

        $this->assertQueuedFor('organization_logo', $org->id);
    }

    public function test_a_blog_cover_upload_is_queued(): void
    {
        Queue::fake();
        $org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'is_default' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $category = Category::firstOrCreate(
            ['slug' => 'cat-1873', 'organization_id' => $org->id],
            ['name_b2c' => 'Test', 'name_b2b' => 'Test B2B', 'color' => '#6366f1'],
        );

        $this->actingAs($user)->post(route('blog.store'), [
            'title' => 'Article T1873',
            'summary' => 'Resume',
            'content' => str_repeat('Contenu de test. ', 5),
            'status' => 'draft',
            'category_id' => $category->id,
            'image' => UploadedFile::fake()->image('couverture.jpg', 1600, 900),
        ])->assertRedirect();

        $this->assertNotNull(BlogPost::where('title', 'Article T1873')->value('image'));
        $this->assertQueuedFor('blog_cover', $org->id);
    }

    public function test_service_images_are_queued_instead_of_the_default_queue_thumbnail_job(): void
    {
        Queue::fake();
        $this->setUpOrganization();
        $user = $this->orgUser(['bio' => 'Test bio that is long enough for service creation.']);

        $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Service T1873',
            'description' => str_repeat('Description assez longue pour la validation du formulaire. ', 3),
            'category_id' => Category::factory()->create()->id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
            'images' => [UploadedFile::fake()->image('a.jpg', 1400, 1000)],
        ])->assertRedirect();

        $service = Service::where('title', 'Service T1873')->firstOrFail();
        $this->assertQueuedFor('service_image', $service->organization_id);
        Queue::assertNotPushed(GenerateServiceThumbnail::class);
    }

    public function test_end_to_end_a_loop_cover_is_served_as_webp_and_its_variants_follow_its_replacement(): void
    {
        // Le worker est simule : le job mis en file est execute ici.
        Queue::fake();
        $org = Organization::factory()->create(['loops_enabled' => true, 'members_can_create_loops' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Boucle couverte',
            'description' => 'Une description.',
            'access_mode' => 'open',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 1800, 1000),
        ])->assertRedirect();

        foreach (Queue::pushed(ProcessImageVariants::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $loop = Loop::where('name', 'Boucle couverte')->firstOrFail();
        $original = $loop->cover_image_path;

        $this->assertSame(3, ImageVariant::where('source_path', $original)->where('status', 'ready')->count());
        $this->assertStringEndsWith('.webp', (string) $loop->cover_image_url);
        $this->assertStringContainsString('/image-variants/cover_md-v1/', (string) $loop->cover_image_url);
        $this->assertStringContainsString(' 480w', (string) $loop->cover_image_srcset);
        Storage::disk('public')->assertExists($original);

        $oldVariants = ImageVariant::where('source_path', $original)->pluck('path');

        // Le proprietaire retire la couverture : l'original part (code
        // existant), ses variantes avec lui.
        $this->actingAs($user)->put(route('loops.update', $loop), [
            'name' => $loop->name,
            'description' => 'Une description.',
            'access_mode' => 'open',
            'remove_cover_image' => '1',
        ]);

        $this->assertNull($loop->fresh()->cover_image_path);
        $this->assertSame(0, ImageVariant::where('source_path', $original)->count());
        foreach ($oldVariants as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_avatar_url_serves_the_variant_once_ready_and_the_original_before(): void
    {
        $user = User::factory()->create(['avatar' => 'avatars/123_photo.jpg']);
        Storage::disk('public')->put('avatars/123_photo.jpg', UploadedFile::fake()->image('p.jpg', 300, 300)->getContent());

        $this->assertSame(Storage::disk('public')->url('avatars/123_photo.jpg'), $user->avatar_url);

        ProcessImageVariants::dispatchSync('user_avatar', 'avatars/123_photo.jpg', null);

        $this->assertStringEndsWith('.webp', User::find($user->id)->avatar_url);
    }
}
