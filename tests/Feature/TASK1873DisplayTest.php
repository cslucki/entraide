<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageVariants;
use App\Models\Loop;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1873 — critere principal : une couverture lourde ne doit plus etre
 * telechargee telle quelle quand elle s'affiche en vignette.
 */
class TASK1873DisplayTest extends TestCase
{
    use RefreshDatabase;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $org = Organization::factory()->create();
        Storage::disk('public')->put('loop-covers/x/cover.png', UploadedFile::fake()->image('cover.png', 1672, 941)->getContent());
        $this->loop = Loop::factory()->create(['organization_id' => $org->id, 'cover_image_path' => 'loop-covers/x/cover.png']);
    }

    public function test_without_variant_the_cover_renders_exactly_as_before(): void
    {
        $html = (string) $this->blade('<x-loops.cover :loop="$loop" />', ['loop' => $this->loop]);

        $this->assertStringContainsString('src="'.Storage::disk('public')->url('loop-covers/x/cover.png').'"', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function test_a_tile_offers_the_480px_webp_and_never_points_its_srcset_at_the_original(): void
    {
        ProcessImageVariants::dispatchSync('loop_cover', 'loop-covers/x/cover.png', $this->loop->organization_id);

        $html = (string) $this->blade(
            '<x-loops.cover :loop="$loop" ratio="1 / 1" sizes="(min-width: 768px) 25vw, 50vw" />',
            ['loop' => Loop::find($this->loop->id)],
        );

        $this->assertMatchesRegularExpression('#srcset="[^"]*\.webp 480w, [^"]*\.webp 960w, [^"]*\.webp 1600w"#', $html);
        $this->assertStringContainsString('sizes="(min-width: 768px) 25vw, 50vw"', $html);
        $this->assertStringNotContainsString('cover.png', $html, "l'original n'est plus propose au navigateur");
        $this->assertStringContainsString('aspect-ratio: 1 / 1', $html);
        $this->assertStringContainsString('object-cover', $html);
    }

    public function test_a_page_banner_is_loaded_eagerly_with_high_priority(): void
    {
        $html = (string) $this->blade('<x-loops.cover :loop="$loop" ratio="21 / 9" :priority="true" />', ['loop' => $this->loop]);

        $this->assertStringContainsString('fetchpriority="high"', $html);
        $this->assertStringNotContainsString('loading="lazy"', $html);
    }

    public function test_the_edit_thumbnail_uses_the_small_variant(): void
    {
        ProcessImageVariants::dispatchSync('loop_cover', 'loop-covers/x/cover.png', $this->loop->organization_id);
        $loop = Loop::find($this->loop->id);

        $this->assertStringContainsString('/image-variants/cover_sm-v1/', (string) $loop->cover_image_thumb_url);
    }
}
