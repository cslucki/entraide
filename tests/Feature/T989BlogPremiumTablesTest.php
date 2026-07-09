<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T989BlogPremiumTablesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private BlogPost $post;

    private Organization $org;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->category = Category::factory()->create(['organization_id' => $this->org->id]);
    }

    private function createPost(array $attrs = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'category_id' => $this->category->id,
            'title' => 'Test Premium Tables',
            'slug' => 'test-premium-tables',
            'summary' => 'Test tables',
            'content' => '<p>Intro</p>',
            'status' => 'published',
            'published_at' => now(),
        ], $attrs));
    }

    public function test_editor_page_renders_table_dropdown(): void
    {
        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->get(route('blog.edit', ['post' => $post]));

        $response->assertOk();
        $response->assertSee(__('blog.editor_table_insert'), false);
    }

    public function test_table_html_preserved_in_published_page(): void
    {
        $tableHtml = '<table><thead><tr><th>Col 1</th><th>Col 2</th></tr></thead><tbody><tr><td>A</td><td>B</td></tr></tbody></table>';
        $post = $this->createPost(['content' => '<p>Intro</p>'.$tableHtml]);

        $response = $this->get(route('blog.show', ['post' => $post]));

        $response->assertOk();
        $response->assertSee('<table>', false);
        $response->assertSee('<th>Col 1</th>', false);
        $response->assertSee('<td>A</td>', false);
    }

    public function test_table_borderless_attribute_preserved(): void
    {
        $tableHtml = '<table data-borderless="true"><thead><tr><th>Col 1</th></tr></thead><tbody><tr><td>A</td></tr></tbody></table>';
        $post = $this->createPost(['content' => '<p>Intro</p>'.$tableHtml]);

        $response = $this->get(route('blog.show', ['post' => $post]));

        $response->assertOk();
        $response->assertSee('data-borderless="true"', false);
    }

    public function test_table_colspan_rowspan_preserved(): void
    {
        $tableHtml = '<table><thead><tr><th colspan="2">Header</th></tr></thead><tbody><tr><td rowspan="2">A</td><td>B</td></tr><tr><td>C</td></tr></tbody></table>';
        $post = $this->createPost(['content' => '<p>Intro</p>'.$tableHtml]);

        $response = $this->get(route('blog.show', ['post' => $post]));

        $response->assertOk();
        $response->assertSee('colspan="2"', false);
        $response->assertSee('rowspan="2"', false);
    }

    public function test_table_i18n_keys_present_in_english(): void
    {
        app()->setLocale('en');

        $keys = [
            'editor_table',
            'editor_table_insert',
            'editor_table_add_row_before',
            'editor_table_add_row_after',
            'editor_table_delete_row',
            'editor_table_add_col_before',
            'editor_table_add_col_after',
            'editor_table_delete_col',
            'editor_table_toggle_header_row',
            'editor_table_toggle_header_col',
            'editor_table_merge_cells',
            'editor_table_split_cell',
            'editor_table_toggle_borders',
            'editor_table_delete',
        ];

        foreach ($keys as $key) {
            $this->assertIsString(__('blog.'.$key), "Missing EN key: blog.{$key}");
        }

        $this->assertEquals('Table', __('blog.editor_table'));
        $this->assertEquals('Insert table', __('blog.editor_table_insert'));
        $this->assertEquals('Toggle table borders', __('blog.editor_table_toggle_borders'));
        $this->assertEquals('Delete table', __('blog.editor_table_delete'));
    }

    public function test_table_i18n_keys_present_in_french(): void
    {
        app()->setLocale('fr');

        $keys = [
            'editor_table',
            'editor_table_insert',
            'editor_table_add_row_before',
            'editor_table_add_row_after',
            'editor_table_delete_row',
            'editor_table_add_col_before',
            'editor_table_add_col_after',
            'editor_table_delete_col',
            'editor_table_toggle_header_row',
            'editor_table_toggle_header_col',
            'editor_table_merge_cells',
            'editor_table_split_cell',
            'editor_table_toggle_borders',
            'editor_table_delete',
        ];

        foreach ($keys as $key) {
            $this->assertIsString(__('blog.'.$key), "Missing FR key: blog.{$key}");
        }

        $this->assertEquals('Tableau', __('blog.editor_table'));
        $this->assertEquals('Insérer un tableau', __('blog.editor_table_insert'));
        $this->assertEquals('Afficher/masquer les bordures', __('blog.editor_table_toggle_borders'));
        $this->assertEquals('Supprimer le tableau', __('blog.editor_table_delete'));
    }

    public function test_published_page_has_borderless_css_classes(): void
    {
        $tableHtml = '<table data-borderless="true"><tr><td>A</td></tr></table>';
        $post = $this->createPost(['content' => '<p>Intro</p>'.$tableHtml]);

        $response = $this->get(route('blog.show', ['post' => $post]));

        $response->assertOk();
        $response->assertSee('[&_table[data-borderless]_th]:border-none', false);
        $response->assertSee('[&_table[data-borderless]_td]:border-none', false);
        $response->assertSee('[&_table]:max-w-full', false);
    }

    public function test_table_sanitization_preserves_tags(): void
    {
        $tableHtml = '<table><thead><tr><th>Col 1</th><th>Col 2</th></tr></thead><tbody><tr><td>A</td><td>B</td></tr></tbody><tfoot><tr><td colspan="2">Footer</td></tr></tfoot></table>';
        $content = '<p>Intro</p>'.$tableHtml;

        $attrs = [
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'category_id' => $this->category->id,
            'title' => 'Sanitized Table',
            'slug' => 'sanitized-table',
            'summary' => 'Test',
            'content' => $content,
            'status' => 'published',
            'published_at' => now(),
        ];

        $post = BlogPost::create($attrs);

        $this->assertEquals($content, $post->fresh()->content);
    }

    public function test_edit_page_loads_blog_editor_component(): void
    {
        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->get(route('blog.edit', ['post' => $post]));

        $response->assertOk();
        $response->assertSee(__('blog.editor_table'), false);
    }

    public function test_col_style_width_preserved_after_sanitization(): void
    {
        $tableHtml = '<table><colgroup><col style="width: 200px"><col style="width: 300px"></colgroup><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>';
        $post = $this->createPost(['content' => '<p>Intro</p>'.$tableHtml]);

        $response = $this->get(route('blog.show', ['post' => $post]));

        $response->assertOk();
        $response->assertSee('style="width: 200px"', false);
        $response->assertSee('style="width: 300px"', false);
    }
}
