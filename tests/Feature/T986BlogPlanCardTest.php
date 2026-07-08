<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T986BlogPlanCardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherUser;

    private BlogPost $post;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->org->id]);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
            'title' => 'Test Plan Card',
            'slug' => 'test-plan-card',
            'summary' => 'Test',
            'content' => '<h2>Intro</h2><p>Text</p><h2>Section 1</h2><p>Text</p><h3>Sous-section</h3><p>Text</p><h4>Detail</h4><p>Text</p><h2>Section 2</h2><p>Text</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_show_toc_column_exists(): void
    {
        $this->assertDatabaseHas('blog_posts', [
            'id' => $this->post->id,
            'show_toc' => false,
        ]);
    }

    public function test_show_toc_defaults_to_false(): void
    {
        $this->assertFalse($this->post->fresh()->show_toc);
    }

    public function test_owner_can_update_show_toc_to_true(): void
    {
        $this->actingAs($this->owner);

        $response = $this->put("/blog/{$this->post->slug}", [
            'title' => $this->post->title,
            'summary' => $this->post->summary,
            'content' => $this->post->content,
            'status' => 'published',
            'category_id' => $this->post->category_id,
            'show_toc' => '1',
        ]);

        $response->assertRedirect();
        $this->assertTrue($this->post->fresh()->show_toc);
    }

    public function test_owner_can_update_show_toc_to_false(): void
    {
        $this->post->update(['show_toc' => true]);
        $this->actingAs($this->owner);

        $response = $this->put("/blog/{$this->post->slug}", [
            'title' => $this->post->title,
            'summary' => $this->post->summary,
            'content' => $this->post->content,
            'status' => 'published',
            'category_id' => $this->post->category_id,
            'show_toc' => '0',
        ]);

        $response->assertRedirect();
        $this->assertFalse($this->post->fresh()->show_toc);
    }

    public function test_show_page_does_not_contain_toc_when_show_toc_is_false(): void
    {
        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertDontSee('plan_title');
    }

    public function test_show_page_contains_toc_when_show_toc_is_true(): void
    {
        $this->post->update(['show_toc' => true]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee('Intro');
        $response->assertSee('Section 1');
        $response->assertSee('Sous-section');
        $response->assertSee('Detail');
        $response->assertSee('Section 2');
    }

    public function test_show_page_has_anchor_ids_on_headings_when_show_toc(): void
    {
        $this->post->update(['show_toc' => true]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee('id="heading-intro"', false);
        $response->assertSee('id="heading-section-1"', false);
        $response->assertSee('id="heading-sous-section"', false);
        $response->assertSee('id="heading-detail"', false);
        $response->assertSee('id="heading-section-2"', false);
    }

    public function test_show_page_contains_toc_nav_structure(): void
    {
        $this->post->update(['show_toc' => true]);

        $response = $this->get("/blog/{$this->post->slug}");

        $response->assertOk();
        $response->assertSee('planToc', false);
        $response->assertSee('heading-intro', false);
        $response->assertSee('heading-detail', false);
        $response->assertSee('heading-section-2', false);
    }

    public function test_non_published_post_shows_404_to_guest(): void
    {
        $draft = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Draft',
            'slug' => 'draft-toc',
            'summary' => 'Test',
            'content' => '<h1>Draft</h1>',
            'status' => 'draft',
            'show_toc' => true,
        ]);

        $response = $this->get("/blog/{$draft->slug}");
        $response->assertNotFound();
    }

    public function test_cross_org_is_blocked(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $otherPost = BlogPost::create([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
            'category_id' => Category::factory()->create(['organization_id' => $otherOrg->id])->id,
            'title' => 'Other',
            'slug' => 'other-plan',
            'summary' => 'Test',
            'content' => '<h1>Other</h1>',
            'status' => 'published',
            'published_at' => now(),
            'show_toc' => true,
        ]);

        $this->actingAs($this->owner);
        $response = $this->get("/blog/{$otherPost->slug}");

        $response->assertNotFound();
    }

    public function test_toc_handles_empty_content(): void
    {
        $empty = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'category_id' => $this->post->category_id,
            'title' => 'Empty',
            'slug' => 'empty-plan',
            'summary' => 'Test',
            'content' => '<p>No headings here</p>',
            'status' => 'published',
            'published_at' => now(),
            'show_toc' => true,
        ]);

        $response = $this->get("/blog/{$empty->slug}");

        $response->assertOk();
        $response->assertDontSee('plan_title');
    }

    public function test_i18n_keys_exist(): void
    {
        $en = require lang_path('en/blog.php');
        $fr = require lang_path('fr/blog.php');

        $keys = [
            'sidebar_plan',
            'sidebar_plan_placeholder',
            'plan_title',
            'plan_empty',
            'plan_toggle',
            'plan_collapse',
            'plan_expand',
            'plan_visible',
            'plan_hidden',
            'plan_updated',
            'plan_update_error',
            'plan_collapse_all',
            'plan_expand_all',
            'plan_loading',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing EN key: {$key}");
            $this->assertArrayHasKey($key, $fr, "Missing FR key: {$key}");
            $this->assertNotEmpty($en[$key], "Empty EN key: {$key}");
            $this->assertNotEmpty($fr[$key], "Empty FR key: {$key}");
        }
    }

    public function test_owner_can_update_plan_show_toc_via_dedicated_endpoint(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson("/blog/{$this->post->slug}/plan", [
            'show_toc' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', __('blog.plan_visible'));
        $this->assertTrue($this->post->fresh()->show_toc);
    }

    public function test_owner_can_hide_toc_via_dedicated_endpoint(): void
    {
        $this->post->update(['show_toc' => true]);
        $this->actingAs($this->owner);

        $response = $this->patchJson("/blog/{$this->post->slug}/plan", [
            'show_toc' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', __('blog.plan_hidden'));
        $this->assertFalse($this->post->fresh()->show_toc);
    }

    public function test_update_plan_requires_show_toc_boolean(): void
    {
        $this->actingAs($this->owner);

        $response = $this->patchJson("/blog/{$this->post->slug}/plan", [
            'show_toc' => 'not-a-boolean',
        ]);

        $response->assertStatus(422);
    }

    public function test_non_editor_cannot_update_plan(): void
    {
        $this->actingAs($this->otherUser);

        $response = $this->patchJson("/blog/{$this->post->slug}/plan", [
            'show_toc' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_guest_cannot_update_plan(): void
    {
        $response = $this->patchJson("/blog/{$this->post->slug}/plan", [
            'show_toc' => true,
        ]);

        $response->assertUnauthorized();
    }

    public function test_cross_org_update_plan_is_blocked(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $otherPost = BlogPost::create([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
            'category_id' => Category::factory()->create(['organization_id' => $otherOrg->id])->id,
            'title' => 'Other',
            'slug' => 'other-plan-update',
            'summary' => 'Test',
            'content' => '<h1>Other</h1>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($this->owner);

        $response = $this->patchJson("/blog/{$otherPost->slug}/plan", [
            'show_toc' => true,
        ]);

        $response->assertNotFound();
    }
}
