<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\User;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class MarkdownToolbarServicesTest extends TestCase
{
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    private function makeService(string $markdown): Service
    {
        $user = $this->orgUser(['bio' => 'Test bio for markdown services that is long enough.']);
        $category = Category::factory()->create();

        return Service::factory()
            ->forUser($user)
            ->forCategory($category)
            ->create([
                'organization_id' => $this->testOrganization->id,
                'description' => $markdown,
            ]);
    }

    private function longText(int $min = 100): string
    {
        return str_repeat('x', $min);
    }

    // ── A. Storage ──────────────────────────────────────────────

    public function test_create_preserves_markdown_in_database(): void
    {
        $user = $this->orgUser(['bio' => 'Markdown test bio.']);
        $category = Category::factory()->create();
        $md = "## Titre\n\n**Gras**\n\n- item\n\n".$this->longText();

        $response = $this->actingAs($user)->post(route('services.store'), [
            'title' => 'Service Markdown',
            'description' => $md,
            'category_id' => $category->id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('services', [
            'title' => 'Service Markdown',
            'description' => $md,
            'organization_id' => $this->testOrganization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_update_preserves_modified_markdown(): void
    {
        $service = $this->makeService("## Original\n\n".$this->longText());
        $user = $service->user;

        $updated = "### Updated\n\nNew **bold** content ".$this->longText();
        $response = $this->actingAs($user)->put(route('services.update', $service), [
            'title' => 'Updated Service Title',
            'description' => $updated,
            'category_id' => $service->category_id,
            'delivery_mode' => 'remote',
            'points_cost' => 100,
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $service->refresh();
        $this->assertSame($updated, $service->description);
    }

    public function test_html_not_destructively_transformed_in_storage(): void
    {
        $raw = "<p>this is raw</p>\n\n## Titre\n\n".$this->longText();
        $service = $this->makeService($raw);

        $db = Service::find($service->id);
        $this->assertEquals($raw, $db->description);
    }

    // ── B. Rendering ────────────────────────────────────────────

    public function test_render_h2_heading(): void
    {
        $service = $this->makeService("## Mon Sous-titre\n\n".$this->longText());
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $response->assertSee('<h2', false);
        $response->assertSee('Mon Sous-titre', false);
    }

    public function test_render_h3_heading(): void
    {
        $service = $this->makeService("### H3 Title\n\n".$this->longText());
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $response->assertSee('<h3', false);
        $response->assertSee('H3 Title', false);
    }

    public function test_render_bold(): void
    {
        $service = $this->makeService("**Texte en gras**\n\n".$this->longText());
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $response->assertSee('<strong', false);
        $response->assertSee('Texte en gras', false);
    }

    public function test_render_bullet_list(): void
    {
        $service = $this->makeService("- Element 1\n- Element 2\n- Element 3\n\n".$this->longText());
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $response->assertSee('<ul', false);
        $response->assertSee('<li', false);
        $response->assertSee('Element 1', false);
        $response->assertSee('Element 2', false);
    }

    public function test_render_safe_link(): void
    {
        $service = $this->makeService("[Site sûr](https://example.com)\n\n".$this->longText());
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $response->assertSee('<a href="https://example.com"', false);
        $response->assertSee('Site sûr', false);
    }

    // ── C. XSS — escaped HTML is safe text, not active code ────

    public function test_xss_script_tag_escaped(): void
    {
        $md = "<script data-task1053-xss>alert(1)</script>\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        // Escaped form IS present (safe text)
        $this->assertStringContainsString('&lt;script', $html);
        $this->assertStringContainsString('data-task1053-xss', $html);
        // Raw active tag is NOT present
        $this->assertStringNotContainsString('<script data-task1053-xss>', $html);
    }

    public function test_xss_img_onerror_escaped(): void
    {
        $md = "<img src=x onerror=alert(1) data-task1053-image>\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        // Escaped form IS present (safe text content)
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('data-task1053-image', $html);
        // Raw active <img tag is NOT present
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    public function test_xss_javascript_markdown_link_stripped(): void
    {
        $md = "[Danger](javascript:alert(1))\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        // allow_unsafe_links=false strips javascript: href from markdown links
        $this->assertStringNotContainsString('javascript:alert(1)', $html);
        // Link text is still rendered
        $this->assertStringContainsString('Danger', $html);
    }

    public function test_xss_anchor_html_javascript_href_escaped(): void
    {
        $md = "<a href=\"javascript:alert(1)\" data-task1053-link>Lien</a>\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        // Escaped form IS present (safe text)
        $this->assertStringContainsString('data-task1053-link', $html);
        // Raw active <a href="javascript: is NOT present
        $this->assertStringNotContainsString('<a href="javascript:', $html);
    }

    public function test_xss_og_description_clean(): void
    {
        $md = "<script data-task1053-og>xss</script>\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        // Description body: escaped form is safe text
        $this->assertStringContainsString('data-task1053-og', $html);
        // Raw active <script> is not present anywhere
        $this->assertStringNotContainsString('<script data-task1053-og>', $html);
    }

    public function test_xss_json_ld_clean(): void
    {
        $md = "<script data-task1053-ld>xss</script>\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        // JSON-LD description is cleaned by strip_tags(Str::markdown(...))
        if (preg_match('/application\/ld\+json/', $html)) {
            $this->assertStringNotContainsString('<script data-task1053-ld>', $html);
        }
    }

    // ── D. Metadata ─────────────────────────────────────────────

    public function test_og_description_no_html_tags(): void
    {
        $md = "## Title\n\n**Bold** and [link](https://example.com)\n\n".$this->longText();
        $service = $this->makeService($md);
        $response = $this->actingAs($service->user)->get(route('services.show', $service));

        $response->assertStatus(200);
        $html = $response->content();
        if (preg_match('/property="og:description"\s+content="([^"]*)"/', $html, $m)) {
            $this->assertStringNotContainsString('<strong>', $m[1]);
            $this->assertStringNotContainsString('<a ', $m[1]);
            $this->assertStringNotContainsString('<h2>', $m[1]);
        }
    }

    // ── E. Toolbar ──────────────────────────────────────────────

    public function test_create_page_has_toolbar_buttons(): void
    {
        $user = $this->orgUser(['bio' => 'Bio sufficient for profile completion.']);

        $response = $this->actingAs($user)->get(route('services.create'));
        $response->assertStatus(200);
        // Alpine insertMarkdown function is rendered
        $response->assertSee('insertMarkdown', false);
        // Textarea has matching id
        $response->assertSee('id="description"', false);
        // Toolbar buttons target description
        $response->assertSee("insertMarkdown('bold')", false);
        $response->assertSee("insertMarkdown('link')", false);
        $response->assertSee("insertMarkdown('h2')", false);
        $response->assertSee("insertMarkdown('h3')", false);
        $response->assertSee("insertMarkdown('list')", false);
    }

    public function test_edit_page_has_toolbar_buttons(): void
    {
        $service = $this->makeService("## Test\n\n".$this->longText());

        $response = $this->actingAs($service->user)->get(route('services.edit', $service));
        $response->assertStatus(200);
        $response->assertSee('insertMarkdown', false);
        $response->assertSee('id="description"', false);
        $response->assertSee("insertMarkdown('bold')", false);
        $response->assertSee("insertMarkdown('h2')", false);
    }

    // ── F. Flux ─────────────────────────────────────────────────

    public function test_flux_create_has_toolbar_behavior(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->testOrganization->id,
            'is_admin' => true,
        ]);
        $this->testOrganization->update(['admin_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(
            route('organization.flux.create', ['organization' => $this->testOrganization->slug])
        );
        $response->assertOk();
        $response->assertSee('insertMarkdown', false);
        $response->assertSee('id="content"', false);
        $response->assertSee("insertMarkdown('bold')", false);
        $response->assertSee("insertMarkdown('link')", false);
        $response->assertSee("insertMarkdown('h2')", false);
        $response->assertSee("insertMarkdown('h3')", false);
        $response->assertSee("insertMarkdown('list')", false);
    }
}
