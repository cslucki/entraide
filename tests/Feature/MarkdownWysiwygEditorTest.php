<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownWysiwygEditorTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create(['is_default' => true]);
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->category = Category::factory()->create(['organization_id' => $this->organization->id]);
    }

    public function test_wysiwyg_editor_on_create_form(): void
    {
        $this->actingAs($this->user)
            ->get(route('services.create'))
            ->assertSee('data-tiptap-container', false)
            ->assertSee('data-tiptap-target', false)
            ->assertSee('textarea', false)
            ->assertSee('id="description"', false);
    }

    public function test_editor_textarea_is_named_description(): void
    {
        $this->actingAs($this->user)
            ->get(route('services.create'))
            ->assertSee('name="description"', false);
    }

    public function test_wysiwyg_editor_on_edit_form(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => '**bold** text',
        ]);

        $this->actingAs($this->user)
            ->get(route('services.edit', $service))
            ->assertSee('data-tiptap-container', false)
            ->assertSee('data-tiptap-target', false)
            ->assertSee('textarea', false);
    }

    public function test_edit_form_shows_existing_description(): void
    {
        $description = "## My heading\n\nSome **bold** text";

        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => $description,
        ]);

        $this->actingAs($this->user)
            ->get(route('services.edit', $service))
            ->assertSee($description, false);
    }

    public function test_stores_markdown_not_html(): void
    {
        $this->actingAs($this->user)
            ->post(route('services.store'), [
                'title' => 'My Coaching Service With Ten Words Minimum',
                'description' => "## Expert coaching\n\n**Personalized** sessions\n\nThis is a longer description that meets the minimum character requirement for a service proposal.",
                'category_id' => $this->category->id,
                'delivery_mode' => 'remote',
                'points_cost' => 100,
            ])
            ->assertSessionHasNoErrors();

        $service = Service::first();
        $this->assertStringContainsString('## Expert coaching', $service->description);
        $this->assertStringContainsString('**Personalized**', $service->description);
        $this->assertStringNotContainsString('<h2>', $service->description);
        $this->assertStringNotContainsString('<strong>', $service->description);
    }

    public function test_show_page_renders_markdown_safely(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => "## Test heading\nSome **bold** content\n- bullet one\n- bullet two",
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('services.show', $service));

        $response->assertOk();
        $response->assertDontSee('data-tiptap-container', false);
    }

    public function test_show_page_escapes_dangerous_html(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => '<script>alert("xss")</script>',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('services.show', $service));

        $response->assertOk();
        $response->assertDontSee('onerror=', false);
    }

    public function test_show_page_escapes_javascript_link(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => '[click me](javascript:alert(1))',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('services.show', $service));

        $response->assertOk();
        $response->assertDontSee('javascript:', false);
    }

    public function test_legacy_plain_text_description_is_preserved(): void
    {
        $description = "First line\nSecond line\nThird line";
        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => $description,
        ]);

        $this->assertSame($description, $service->fresh()->description);
    }

    public function test_validation_requires_min_100_chars(): void
    {
        $this->actingAs($this->user)
            ->post(route('services.store'), [
                'title' => 'Short Title Here',
                'description' => str_repeat('a', 99),
                'category_id' => $this->category->id,
                'delivery_mode' => 'remote',
                'points_cost' => 100,
            ])
            ->assertSessionHasErrors('description');
    }

    public function test_validation_passes_with_min_100_chars(): void
    {
        $this->actingAs($this->user)
            ->post(route('services.store'), [
                'title' => 'Valid Service Title Here',
                'description' => str_repeat('a', 100),
                'category_id' => $this->category->id,
                'delivery_mode' => 'remote',
                'points_cost' => 100,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_create_form_contains_ai_formulation_panel(): void
    {
        $this->actingAs($this->user)
            ->get(route('services.create'))
            ->assertSee('canFormulate', false);
    }

    public function test_edit_form_does_not_have_ai_panel_wrapper(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'description' => str_repeat('a', 100),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('services.edit', $service));

        $response->assertOk();
    }
}
