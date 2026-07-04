<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class T218AdminBlogEditAuthorMarkdownTest extends TestCase
{
    private Organization $organization;

    private User $admin;

    private User $author;

    private User $newAuthor;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->author = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        $this->newAuthor = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article original',
            'slug' => 'article-original',
            'content' => str_repeat('Contenu de test. ', 10),
            'status' => 'draft',
        ]);
    }

    public function test_admin_can_access_edit_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.blog.edit', $this->post))
            ->assertOk()
            ->assertSee('Article original')
            ->assertSee($this->author->name)
            ->assertSee($this->newAuthor->name);
    }

    public function test_non_admin_cannot_access_edit_form(): void
    {
        $this->actingAs($this->author)
            ->get(route('admin.blog.edit', $this->post))
            ->assertForbidden();
    }

    public function test_guest_cannot_access_edit_form(): void
    {
        $this->get(route('admin.blog.edit', $this->post))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_update_post_title_and_content(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.blog.update', $this->post), [
                'organization_id' => $this->post->organization_id,
                'user_id' => $this->author->id,
                'title' => 'Titre modifié',
                'content' => str_repeat('Nouveau contenu mis à jour. ', 10),
                'status' => 'published',
            ])
            ->assertSessionHas('success')
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $this->post->id,
            'title' => 'Titre modifié',
            'status' => 'published',
        ]);

        $this->assertNotNull($this->post->fresh()->published_at);
    }

    public function test_admin_can_change_post_author(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.blog.update', $this->post), [
                'organization_id' => $this->post->organization_id,
                'user_id' => $this->newAuthor->id,
                'title' => $this->post->title,
                'content' => $this->post->content,
                'status' => $this->post->status,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $this->post->id,
            'user_id' => $this->newAuthor->id,
        ]);
    }

    public function test_admin_can_update_post_status(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.blog.status', $this->post), [
                'status' => 'published',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $this->post->id,
            'status' => 'published',
        ]);
    }

    public function test_admin_can_destroy_post(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.blog.destroy', $this->post))
            ->assertRedirect();

        $this->assertSoftDeleted($this->post);
    }

    public function test_preview_markdown_renders_html(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.blog.preview-markdown'), [
                'content' => '# Titre H1'."\n\n".
                             'Paragraphe avec **gras** et *italique*.'."\n\n".
                             "- Liste item 1\n- Liste item 2\n".
                             "```php\necho 'hello';\n```",
            ]);

        $response->assertOk()
            ->assertJsonStructure(['html']);

        $html = $response->json('html');
        $this->assertStringContainsString('<h1>Titre H1</h1>', $html);
        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringContainsString('<em>italique</em>', $html);
        $this->assertStringContainsString('<li>Liste item 1</li>', $html);
        $this->assertStringContainsString('<code class="language-php">echo', $html);
    }

    public function test_non_admin_cannot_preview_markdown(): void
    {
        $this->actingAs($this->author)
            ->post(route('admin.blog.preview-markdown'), [
                'content' => '# Test',
            ])
            ->assertForbidden();
    }

    public function test_public_blog_show_renders_content(): void
    {
        $post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article HTML',
            'slug' => 'article-html-test',
            'content' => '<h2>Titre</h2><p>Texte avec <strong>gras</strong> et <em>italique</em>.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('blog.show', $post))
            ->assertOk()
            ->assertSee('<h2>Titre</h2>', false)
            ->assertSee('<strong>gras</strong>', false)
            ->assertSee('<em>italique</em>', false);
    }

    public function test_edit_form_shows_current_author_selected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.blog.edit', $this->post))
            ->assertOk()
            ->assertSee($this->author->email)
            ->assertSee($this->newAuthor->email);
    }

    public function test_admin_can_update_post_with_category_and_tags(): void
    {
        $category = Category::create([
            'name_b2c' => 'Test Cat',
            'name_b2b' => 'Test Cat B2B',
            'slug' => 'test-cat-'.uniqid(),
            'color' => '#6366f1',
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.blog.update', $this->post), [
                'organization_id' => $this->post->organization_id,
                'user_id' => $this->author->id,
                'title' => 'Avec catégorie',
                'content' => str_repeat('Contenu avec catégorie. ', 10),
                'status' => 'draft',
                'category_id' => $category->id,
                'tags' => 'laravel, php, test',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $this->post->id,
            'category_id' => $category->id,
        ]);

        $this->assertCount(3, $this->post->fresh()->tags);
    }

    public function test_preview_markdown_requires_authentication(): void
    {
        $this->post(route('admin.blog.preview-markdown'), [
            'content' => '# Test',
        ])->assertRedirect(route('login'));
    }
}
