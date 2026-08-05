<?php

namespace Tests\Feature;

use App\Http\Controllers\BlogController;
use App\Http\Controllers\BlogSnapshotController;
use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
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

    // ── Série : promouvoir une annexe en racine ─────────────────────────────

    public function test_an_annex_can_be_promoted_to_root(): void
    {
        // This is what "change root" was supposed to do and never did: the
        // endpoint refused any article already an annexe of the series, which
        // is precisely every candidate.
        [$dossier, $series, $annex, $formerRoot] = $this->seriesFixture();

        $this->actingAs($this->author)
            ->patchJson(route('organization.dossiers.series.update', [
                'organization' => $this->org->slug, 'dossier' => $dossier->id,
            ]), ['root_blog_post_id' => $annex->id])
            ->assertOk();

        $this->assertSame($annex->id, $series->fresh()->root_blog_post_id);
    }

    public function test_the_former_root_becomes_the_first_annex(): void
    {
        // Nothing a human placed in the series is ever dropped.
        [$dossier, $series, $annex, $formerRoot] = $this->seriesFixture();

        $this->actingAs($this->author)
            ->patchJson(route('organization.dossiers.series.update', [
                'organization' => $this->org->slug, 'dossier' => $dossier->id,
            ]), ['root_blog_post_id' => $annex->id])
            ->assertOk();

        $this->assertDatabaseHas('article_series_items', [
            'article_series_id' => $series->id,
            'blog_post_id' => $formerRoot->id,
            'position' => 0,
        ]);

        // And the promoted one is no longer an annexe of its own series.
        $this->assertDatabaseMissing('article_series_items', [
            'article_series_id' => $series->id,
            'blog_post_id' => $annex->id,
        ]);
    }

    public function test_an_article_outside_the_dossier_cannot_become_root(): void
    {
        [$dossier] = $this->seriesFixture();
        $outsider = $this->article();

        $this->actingAs($this->author)
            ->patchJson(route('organization.dossiers.series.update', [
                'organization' => $this->org->slug, 'dossier' => $dossier->id,
            ]), ['root_blog_post_id' => $outsider->id])
            ->assertNotFound();
    }

    /** @return array{0:Dossier,1:ArticleSeries,2:BlogPost,3:BlogPost} */
    private function seriesFixture(): array
    {
        $dossier = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->author->id,
            'name' => 'Dossier serie', 'visibility' => 'private',
        ]);

        $root = $this->article();
        $annex = $this->article();

        foreach ([$root, $annex] as $i => $post) {
            DossierBlogPost::create([
                'organization_id' => $this->org->id, 'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id, 'position' => $i,
            ]);
        }

        $series = ArticleSeries::create([
            'organization_id' => $this->org->id, 'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id, 'created_by' => $this->author->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->org->id, 'article_series_id' => $series->id,
            'blog_post_id' => $annex->id, 'position' => 0,
        ]);

        return [$dossier, $series, $annex, $root];
    }

    public function test_leaving_a_dossier_also_leaves_its_series(): void
    {
        // The visible symptom was a refusal that made no sense: "this article is
        // already the root of a series" on an article sitting alone in a
        // brand-new Dossier — because it was still the root of the Series it
        // had been moved out of.
        [$dossier, $series, $annex, $root] = $this->seriesFixture();

        $this->actingAs($this->author)
            ->deleteJson(route('organization.blog.dossier.detach', [
                'organization' => $this->org->slug, 'post' => $annex->slug,
            ]))
            ->assertOk();

        $this->assertDatabaseMissing('article_series_items', [
            'article_series_id' => $series->id, 'blog_post_id' => $annex->id,
        ]);
    }

    public function test_removing_the_root_promotes_the_next_article_rather_than_dropping_the_series(): void
    {
        // A Series that loses its root still holds what someone put in it.
        [$dossier, $series, $annex, $root] = $this->seriesFixture();

        $this->actingAs($this->author)
            ->deleteJson(route('organization.blog.dossier.detach', [
                'organization' => $this->org->slug, 'post' => $root->slug,
            ]))
            ->assertOk();

        $this->assertSame($annex->id, $series->fresh()?->root_blog_post_id);
    }

    public function test_a_series_left_with_nothing_is_removed(): void
    {
        [$dossier, $series, $annex, $root] = $this->seriesFixture();

        foreach ([$annex, $root] as $post) {
            $this->actingAs($this->author)->deleteJson(route('organization.blog.dossier.detach', [
                'organization' => $this->org->slug, 'post' => $post->slug,
            ]));
        }

        $this->assertNull($series->fresh());
    }

    // ── Card Dossier de l'éditeur ───────────────────────────────────────────

    public function test_the_editor_card_carries_the_dossier_url_and_the_series(): void
    {
        // Reaching the Dossier meant going back to "Mes dossiers" and hunting;
        // and nothing told the writer they were inside a series.
        [$dossier, $series, $annex, $root] = $this->seriesFixture();

        $this->actingAs($this->author)
            ->getJson(route('organization.blog.dossier.current', [
                'organization' => $this->org->slug, 'post' => $annex->slug,
            ]))
            ->assertOk()
            ->assertJsonPath('dossier.id', $dossier->id)
            ->assertJsonPath('dossier.series.is_root', false)
            ->assertJsonPath('dossier.series.root_title', $root->title)
            ->assertJsonStructure(['dossier' => ['url']]);
    }

    public function test_an_article_outside_any_series_reports_none(): void
    {
        $dossier = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->author->id,
            'name' => 'Sans serie', 'visibility' => 'private',
        ]);
        $post = $this->article();
        DossierBlogPost::create([
            'organization_id' => $this->org->id, 'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id, 'position' => 0,
        ]);

        $this->actingAs($this->author)
            ->getJson(route('organization.blog.dossier.current', [
                'organization' => $this->org->slug, 'post' => $post->slug,
            ]))
            ->assertOk()
            ->assertJsonPath('dossier.series', null);
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
