<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T993BlogTocAdvancedNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
            'title' => 'Advanced TOC',
            'slug' => 'advanced-toc',
            'summary' => 'Advanced table of contents preferences.',
            'content' => '<h2>Intro</h2><p>Text</p><h3>Context</h3><p>Text</p><h4>Detail</h4><p>Text</p><h2>Next</h2><p>Text</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_toc_preferences_default_on_blog_posts(): void
    {
        $post = $this->post->fresh();

        $this->assertFalse($post->show_toc);
        $this->assertSame(4, $post->toc_max_level);
        $this->assertFalse($post->toc_navigation_enabled);
    }

    public function test_owner_can_save_toc_preferences(): void
    {
        $this->actingAs($this->owner);

        $response = $this->put("/blog/{$this->post->slug}", [
            'title' => $this->post->title,
            'summary' => $this->post->summary,
            'content' => $this->post->content,
            'status' => 'published',
            'category_id' => $this->post->category_id,
            'show_toc' => '1',
            'toc_max_level' => '3',
            'toc_navigation_enabled' => '1',
        ]);

        $response->assertRedirect();

        $post = $this->post->fresh();
        $this->assertTrue($post->show_toc);
        $this->assertSame(3, $post->toc_max_level);
        $this->assertTrue($post->toc_navigation_enabled);
    }

    public function test_toc_max_level_filters_published_headings(): void
    {
        $this->post->update([
            'show_toc' => true,
            'toc_max_level' => 2,
        ]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee('id="heading-intro"', false);
        $response->assertSee('id="heading-next"', false);
        $response->assertDontSee('id="heading-context"', false);
        $response->assertDontSee('id="heading-detail"', false);
    }

    public function test_toc_max_level_can_include_h3_without_h4(): void
    {
        $this->post->update([
            'show_toc' => true,
            'toc_max_level' => 3,
        ]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee('id="heading-intro"', false);
        $response->assertSee('id="heading-context"', false);
        $response->assertDontSee('id="heading-detail"', false);
    }

    public function test_navigation_mode_renders_mobile_compact_and_desktop_sticky_navigation(): void
    {
        $this->post->update([
            'show_toc' => true,
            'toc_max_level' => 4,
            'toc_navigation_enabled' => true,
        ]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee(__('blog.toc_navigation_title'));
        $response->assertSee(__('blog.toc_publication_title'));
        $response->assertSee('lg:hidden', false);
        $response->assertSee('lg:sticky', false);
        $response->assertSee('lg:max-h-[calc(100vh-7rem)]', false);
        $response->assertSee('lg:overflow-y-auto', false);
        $response->assertSee('heading-detail', false);
    }

    public function test_toc_publication_options_are_in_main_form_not_plan_sidebar(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get(route('blog.edit', ['post' => $this->post]));

        $response->assertOk();
        $response->assertSee(__('blog.toc_section_title'));
        $response->assertSee(__('blog.toc_use'));
        $response->assertSee(__('blog.toc_detail_level'));
        $response->assertSee(__('blog.toc_navigation_enabled'));
        $response->assertSee(__('blog.sidebar_plan'));
        $response->assertDontSee(__('blog.plan_toggle'));
    }

    public function test_back_to_outline_button_renders_in_normal_toc_mode(): void
    {
        $this->post->update([
            'show_toc' => true,
            'toc_max_level' => 4,
            'toc_navigation_enabled' => false,
        ]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee(__('blog.back_to_outline'));
        $response->assertSee('hidden lg:flex', false);
        $response->assertSee('fixed bottom-6 right-6', false);
        $response->assertSee('@click', false);
    }

    public function test_back_to_outline_button_absent_in_navigation_mode(): void
    {
        $this->post->update([
            'show_toc' => true,
            'toc_max_level' => 4,
            'toc_navigation_enabled' => true,
        ]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertDontSee(__('blog.back_to_outline'));
    }

    public function test_back_to_outline_button_absent_when_toc_disabled(): void
    {
        $this->post->update([
            'show_toc' => false,
        ]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertDontSee(__('blog.back_to_outline'));
    }

    public function test_toc_i18n_keys_exist_in_both_locales(): void
    {
        $en = require lang_path('en/blog.php');
        $fr = require lang_path('fr/blog.php');

        $keys = [
            'toc_section_title',
            'toc_section_help',
            'toc_use',
            'toc_detail_level',
            'toc_level_h2',
            'toc_level_h2_h3',
            'toc_level_h2_h3_h4',
            'toc_navigation_enabled',
            'toc_navigation_help',
            'toc_publication_title',
            'toc_navigation_title',
            'toc_navigation_label',
            'back_to_outline',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing EN key: {$key}");
            $this->assertArrayHasKey($key, $fr, "Missing FR key: {$key}");
            $this->assertNotEmpty($en[$key], "Empty EN key: {$key}");
            $this->assertNotEmpty($fr[$key], "Empty FR key: {$key}");
        }
    }
}
