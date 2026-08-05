<?php

namespace Tests\Feature;

use App\Http\Controllers\BlogController;
use App\Http\Controllers\BlogSnapshotController;
use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Three fixes to the Article editor.
 *
 * Pasting a Markdown document used to produce one grey code block; there was no
 * way to see the result before saving; and a category was compulsory even
 * though 89 articles already had none.
 *
 * The Markdown detection itself lives in JavaScript and is covered by its own
 * unit tests. What is pinned here is everything the server decides: the
 * category is genuinely optional, the preview writes nothing, and neither
 * change loosens tenant isolation.
 */
class TASK1084ArticleEditorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->author = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $this->category = Category::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function article(array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'title' => 'Article '.uniqid(),
            'slug' => 'article-'.uniqid(),
            'content' => '<p>Contenu</p>',
            'status' => 'draft',
        ], $overrides));
    }

    // ── Catégorie facultative ───────────────────────────────────────────────

    public function test_the_column_is_already_nullable_so_no_migration_is_needed(): void
    {
        $post = $this->article(['category_id' => null]);

        $this->assertNull($post->fresh()->category_id);
    }

    public function test_an_article_can_be_created_without_a_category(): void
    {
        $this->actingAs($this->author)
            ->post(route('blog.store'), [
                'title' => 'Sans categorie',
                'summary' => 'Un resume',
                'content' => '<p>Du contenu</p>',
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blog_posts', ['title' => 'Sans categorie', 'category_id' => null]);
    }

    public function test_an_article_can_be_published_without_a_category(): void
    {
        // Publishing used to require one. Publishing is not a reason to invent
        // a category, and no "Divers" placeholder is created to work around it.
        $this->actingAs($this->author)
            ->post(route('blog.store'), [
                'title' => 'Publie sans categorie',
                'summary' => 'Un resume',
                'content' => '<p>Du contenu</p>',
                'status' => 'published',
            ])
            ->assertSessionHasNoErrors();

        $post = BlogPost::where('title', 'Publie sans categorie')->first();

        $this->assertNotNull($post);
        $this->assertNull($post->category_id);
    }

    public function test_a_category_can_still_be_chosen(): void
    {
        $this->actingAs($this->author)
            ->post(route('blog.store'), [
                'title' => 'Avec categorie',
                'summary' => 'Un resume',
                'content' => '<p>Du contenu</p>',
                'status' => 'draft',
                'category_id' => $this->category->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blog_posts', [
            'title' => 'Avec categorie', 'category_id' => $this->category->id,
        ]);
    }

    public function test_a_category_of_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreign = Category::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->author)
            ->post(route('blog.store'), [
                'title' => 'Categorie etrangere',
                'summary' => 'Un resume',
                'content' => '<p>Du contenu</p>',
                'status' => 'draft',
                'category_id' => $foreign->id,
            ]);

        $this->assertDatabaseMissing('blog_posts', [
            'title' => 'Categorie etrangere', 'category_id' => $foreign->id,
        ]);
    }

    public function test_an_invented_category_id_is_refused(): void
    {
        $this->actingAs($this->author)
            ->post(route('blog.store'), [
                'title' => 'Categorie inventee',
                'summary' => 'Un resume',
                'content' => '<p>Du contenu</p>',
                'status' => 'draft',
                'category_id' => '019f0000-0000-7000-8000-000000000000',
            ])
            ->assertSessionHasErrors('category_id');
    }

    public function test_an_article_without_a_category_renders_without_breaking(): void
    {
        $post = $this->article([
            'category_id' => null, 'status' => 'published', 'published_at' => now(),
        ]);

        // No property read on null, no empty badge, no broken markup.
        $this->actingAs($this->author)
            ->get(route('blog.show', $post->slug))
            ->assertOk();
    }

    public function test_the_listing_survives_articles_without_a_category(): void
    {
        $this->article(['category_id' => null, 'status' => 'published', 'published_at' => now()]);
        $this->article(['category_id' => $this->category->id, 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->author)->get(route('blog.index'))->assertOk();
    }

    // ── Prévisualisation ────────────────────────────────────────────────────

    public function test_the_creation_page_is_a_pre_step_without_an_editor(): void
    {
        // `/blog/rediger/nouveau` only asks for a title and a summary, then
        // offers to generate with AI or to write yourself. There is no content
        // editor on it, so there is nothing to preview — the button belongs on
        // the editor page, which the next test covers.
        $this->actingAs($this->author)
            ->get(route('blog.create'))
            ->assertOk()
            ->assertDontSee('data-blog-preview-button', false);
    }

    public function test_the_preview_button_is_offered_when_editing(): void
    {
        $post = $this->article();

        $this->actingAs($this->author)
            ->get(route('blog.edit', $post->slug))
            ->assertOk()
            ->assertSee(__('blog.preview'))
            ->assertSee('data-blog-preview-button', false);
    }

    public function test_the_preview_writes_nothing(): void
    {
        // It is entirely client-side: no route, no request, therefore no way to
        // save, publish, change a status or take a snapshot by opening it.
        $post = $this->article(['status' => 'draft']);
        $before = $post->fresh()->only(['title', 'content', 'status', 'published_at']);

        $this->actingAs($this->author)->get(route('blog.edit', $post->slug))->assertOk();

        $this->assertSame($before, $post->fresh()->only(['title', 'content', 'status', 'published_at']));
        $this->assertSame(0, BlogSnapshot::where('blog_post_id', $post->id)->count());
    }

    // ── Le titre de niveau 1 survit à l'enregistrement ──────────────────────

    public function test_a_level_one_title_survives_saving(): void
    {
        // The bug this pins down was silent: the editor produced a proper <h1>,
        // the allowlist did not contain it, and strip_tags() removed the tag
        // while keeping its text. A level-1 title came back as an ordinary
        // paragraph after saving, with nothing on screen to explain why.
        $post = $this->article();

        $this->actingAs($this->author)
            ->put(route('blog.update', $post->slug), [
                'title' => $post->title,
                'summary' => 'Un resume',
                'content' => '<h1>Titre de niveau 1</h1><p>Du texte.</p>',
                'status' => 'draft',
            ]);

        $this->assertStringContainsString('<h1>Titre de niveau 1</h1>', $post->fresh()->content);
    }

    public function test_every_html_allowlist_accepts_h1(): void
    {
        // Four allowlists guard article HTML, and the level-1 title has to pass
        // all of them: miss one and the tag is dropped at that step only —
        // saving, restoring a snapshot, or rendering a Loop root document.
        $reflect = fn (string $class, string $const) => (new \ReflectionClass($class))->getConstant($const);

        $this->assertContains('h1', $reflect(BlogController::class, 'ALLOWED_HTML_TAGS'));
        $this->assertContains('h1', $reflect(BlogSnapshotController::class, 'ALLOWED_HTML_TAGS'));

        // The two Loop-side allowlists are inline; assert on their behaviour.
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        $doc = $this->article(['content' => '<h1>Racine</h1><p>Corps.</p>']);
        $loop->forceFill(['manifesto_blog_post_id' => $doc->id])->save();

        $this->assertStringContainsString('<h1>Racine</h1>', $loop->fresh()->manifestoHtmlForAdmin());
    }

    // ── Non-régression documents racine ─────────────────────────────────────

    public function test_a_loop_root_document_keeps_its_audience_and_stays_out_of_the_blog(): void
    {
        $root = $this->article([
            'status' => 'published', 'published_at' => now(),
            'audience' => BlogPost::AUDIENCE_LOOP, 'listed_in_blog' => false,
            'category_id' => null,
        ]);

        $fresh = $root->fresh();

        $this->assertSame(BlogPost::AUDIENCE_LOOP, $fresh->audience);
        $this->assertFalse((bool) $fresh->listed_in_blog);
        $this->assertNull(BlogPost::published()->find($root->id));
    }
}
