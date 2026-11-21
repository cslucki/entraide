<?php

namespace Tests\Feature;

use App\Models\ImageVariant;
use App\Models\Organization;
use App\Support\Images\ImageProcessOutcome;
use App\Support\Images\ImageSource;
use App\Support\Images\ImageVariantResolver;
use App\Support\Images\ImageVariantService;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1873 — le moteur de derivation : ce qu'il produit, ce qu'il refuse,
 * et ce qu'il ne touche jamais (l'original).
 */
class TASK1873ImageVariantServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function putImage(string $path, int $width, int $height, string $name = 'photo.jpg'): string
    {
        $file = UploadedFile::fake()->image($name, $width, $height);
        Storage::disk('public')->put($path, $file->getContent());

        return Storage::disk('public')->get($path);
    }

    private function service(): ImageVariantService
    {
        return app(ImageVariantService::class);
    }

    public function test_a_cover_produces_three_proportional_webp_variants_and_leaves_the_original_intact(): void
    {
        $original = $this->putImage('loop-covers/l1/cover.jpg', 2000, 1200);
        $org = Organization::factory()->create();

        $outcome = $this->service()->process(new ImageSource('loop_cover', 'loop-covers/l1/cover.jpg', $org->id));

        $this->assertSame(ImageProcessOutcome::PROCESSED, $outcome->status);
        $this->assertSame(3, $outcome->generated);

        $expected = ['cover_sm' => [480, 288], 'cover_md' => [960, 576], 'cover_lg' => [1600, 960]];

        foreach ($expected as $preset => [$w, $h]) {
            $row = ImageVariant::where('source_path', 'loop-covers/l1/cover.jpg')->where('preset', $preset)->firstOrFail();

            $this->assertSame(ImageVariant::STATUS_READY, $row->status);
            $this->assertSame($org->id, $row->organization_id);
            $this->assertSame([$w, $h], [$row->width, $row->height], "{$preset} : ratio preserve, sans recadrage");

            $bytes = Storage::disk('public')->get($row->path);
            $info = getimagesizefromstring($bytes);
            $this->assertSame('image/webp', $info['mime']);
            $this->assertSame([$w, $h], [$info[0], $info[1]]);
            $this->assertStringStartsWith('image-variants/'.$preset.'-v1/', $row->path);
            $this->assertStringNotContainsString('cover.jpg', $row->path, 'aucun nom client dans le chemin derive');
        }

        $this->assertSame($original, Storage::disk('public')->get('loop-covers/l1/cover.jpg'), "l'original n'est jamais reecrit");
    }

    public function test_an_avatar_is_cropped_square(): void
    {
        $this->putImage('avatars/a.jpg', 800, 500);

        $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));

        $row = ImageVariant::where('preset', 'avatar')->firstOrFail();
        $this->assertSame([192, 192], [$row->width, $row->height]);
        $this->assertNull($row->organization_id);
    }

    public function test_a_small_image_is_never_upscaled(): void
    {
        $this->putImage('blog/small.png', 300, 200, 'small.png');

        $this->service()->process(new ImageSource('blog_cover', 'blog/small.png'));

        $row = ImageVariant::where('preset', 'cover_lg')->firstOrFail();
        $this->assertSame([300, 200], [$row->width, $row->height]);
    }

    public function test_a_second_pass_is_idempotent_and_does_not_even_read_the_source(): void
    {
        $this->putImage('avatars/a.jpg', 400, 400);
        $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));
        $before = ImageVariant::firstOrFail();

        // La source disparait : un second passage qui la relirait la
        // declarerait manquante. Il ne doit pas la relire.
        Storage::disk('public')->delete('avatars/a.jpg');
        $this->travel(5)->minutes();

        $outcome = $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));

        $this->assertSame(ImageProcessOutcome::UP_TO_DATE, $outcome->status);
        $this->assertSame(1, ImageVariant::count());
        $this->assertEquals($before->processed_at, ImageVariant::firstOrFail()->processed_at);
    }

    public function test_a_preset_version_bump_regenerates_and_removes_only_the_old_derived_file(): void
    {
        $this->putImage('avatars/a.jpg', 400, 400);
        $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));
        $oldPath = ImageVariant::firstOrFail()->path;

        config(['image_variants.presets.avatar.version' => 2, 'image_variants.presets.avatar.width' => 128, 'image_variants.presets.avatar.height' => 128]);

        $outcome = $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));

        $row = ImageVariant::firstOrFail();
        $this->assertSame(ImageProcessOutcome::PROCESSED, $outcome->status);
        $this->assertSame(2, $row->preset_version);
        $this->assertSame([128, 128], [$row->width, $row->height]);
        $this->assertNotSame($oldPath, $row->path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($row->path);
        Storage::disk('public')->assertExists('avatars/a.jpg');
    }

    public function test_a_missing_source_is_recorded_without_any_write(): void
    {
        $outcome = $this->service()->process(new ImageSource('user_avatar', 'avatars/ghost.jpg'));

        $this->assertSame(ImageProcessOutcome::MISSING, $outcome->status);
        $this->assertSame(ImageVariant::STATUS_MISSING, ImageVariant::firstOrFail()->status);
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
    }

    public function test_an_oversized_image_is_skipped_before_decoding_and_the_original_stays_served(): void
    {
        config(['image_variants.max_pixels' => 1000 * 1000]);
        $this->putImage('blog/huge.jpg', 1200, 1000);

        $outcome = $this->service()->process(new ImageSource('blog_cover', 'blog/huge.jpg'));

        $this->assertSame(ImageProcessOutcome::SKIPPED, $outcome->status);
        $this->assertStringStartsWith('too_large:1200x1000', (string) $outcome->reason);
        $this->assertSame([], Storage::disk('public')->allFiles('image-variants'));
        $this->assertSame(
            Storage::disk('public')->url('blog/huge.jpg'),
            app(ImageVariantResolver::class)->url('blog/huge.jpg', 'cover_md'),
        );
    }

    public function test_a_non_raster_file_is_skipped(): void
    {
        Storage::disk('public')->put('organization-logos/o/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $outcome = $this->service()->process(new ImageSource('organization_logo', 'organization-logos/o/logo.svg'));

        $this->assertSame(ImageProcessOutcome::SKIPPED, $outcome->status);
    }

    public function test_a_shared_scenario_bank_avatar_is_never_processed(): void
    {
        $key = ManifestAvatarBank::keys('fictional-avatars-v1')[0];
        $path = ManifestAvatarBank::publishedPath('fictional-avatars-v1', $key);
        $this->assertNotNull($path);
        Storage::disk('public')->put($path, '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $outcome = $this->service()->process(new ImageSource('user_avatar', $path));

        $this->assertSame(ImageProcessOutcome::SKIPPED, $outcome->status);
        $this->assertSame('shared_asset', $outcome->reason);
        $this->assertSame(0, ImageVariant::count());
    }

    public function test_a_concurrent_run_on_the_same_source_backs_off(): void
    {
        $this->putImage('avatars/a.jpg', 400, 400);
        $lock = Cache::lock('image-variants:'.sha1('public|avatars/a.jpg'), 60);
        $this->assertTrue($lock->get());

        $outcome = $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));

        $this->assertSame(ImageProcessOutcome::LOCKED, $outcome->status);
        $this->assertSame(0, ImageVariant::count());
        $lock->release();
    }

    public function test_forget_removes_the_variants_but_never_an_original(): void
    {
        $this->putImage('avatars/a.jpg', 400, 400);
        $this->service()->process(new ImageSource('user_avatar', 'avatars/a.jpg'));
        $variant = ImageVariant::firstOrFail();

        // Un registre corrompu qui pointerait sur un original ne doit pas
        // faire supprimer cet original.
        $this->putImage('avatars/b.jpg', 50, 50);
        ImageVariant::create([
            'surface' => 'user_avatar', 'disk' => 'public', 'source_path' => 'avatars/a.jpg',
            'preset' => 'rogue', 'preset_version' => 1, 'status' => 'ready', 'path' => 'avatars/b.jpg',
        ]);

        $this->service()->forget('avatars/a.jpg');

        $this->assertSame(0, ImageVariant::count());
        Storage::disk('public')->assertMissing($variant->path);
        Storage::disk('public')->assertExists('avatars/a.jpg');
        Storage::disk('public')->assertExists('avatars/b.jpg');
    }

    public function test_the_resolver_falls_back_to_the_original_then_serves_the_variant(): void
    {
        $this->putImage('blog/c.jpg', 2000, 1000);
        $resolver = app(ImageVariantResolver::class);

        $this->assertSame(Storage::disk('public')->url('blog/c.jpg'), $resolver->url('blog/c.jpg', 'cover_md'));
        $this->assertNull($resolver->srcset('blog/c.jpg', ['cover_sm', 'cover_md']));
        $this->assertNull($resolver->url(null, 'cover_md'));

        $this->service()->process(new ImageSource('blog_cover', 'blog/c.jpg'));

        $md = ImageVariant::where('preset', 'cover_md')->firstOrFail();
        $sm = ImageVariant::where('preset', 'cover_sm')->firstOrFail();
        $this->assertSame(Storage::disk('public')->url($md->path), $resolver->url('blog/c.jpg', 'cover_md'));
        $this->assertSame(
            Storage::disk('public')->url($sm->path).' 480w, '.Storage::disk('public')->url($md->path).' 960w',
            $resolver->srcset('blog/c.jpg', ['cover_md', 'cover_sm']),
        );
    }

    public function test_an_unreadable_image_is_skipped_once_and_not_picked_again(): void
    {
        Storage::disk('public')->put('avatars/broken.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 64));

        // Un en-tete JPEG tronque : `getimagesize` le refuse, c'est un skip.
        $outcome = $this->service()->process(new ImageSource('user_avatar', 'avatars/broken.jpg'));
        $this->assertSame(ImageProcessOutcome::SKIPPED, $outcome->status);
        $this->assertFalse($this->service()->needsWork(new ImageSource('user_avatar', 'avatars/broken.jpg')));
    }
}
