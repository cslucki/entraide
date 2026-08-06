<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DossierSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $editorA;

    private User $readerA;

    private User $strangerA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org A', 'slug' => 'org-a', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'Org B', 'slug' => 'org-b', 'is_active' => true]);

        $this->ownerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->editorA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->readerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->strangerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->userB = User::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function dossier(Organization $org, User $owner, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function blogPost(Organization $org, User $author, string $title): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $org->id,
            'user_id' => $author->id,
            'title' => $title,
            'content' => "Content for {$title}.",
            'status' => 'draft',
        ]);
    }

    private function attach(Dossier $dossier, BlogPost $post, User $user, int $position): DossierBlogPost
    {
        return DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $user->id,
            'position' => $position,
        ]);
    }

    private function orgRoute(string $name, Dossier $dossier, array $extra = []): string
    {
        return route("organization.{$name}", array_merge([
            'organization' => $this->orgA->slug,
            'dossier' => $dossier->id,
        ], $extra));
    }

    // --- Search endpoint exclusion tests ---

    public function test_search_excludes_articles_already_attached_to_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Search test');
        $free = $this->blogPost($this->orgA, $this->ownerA, 'Free article');
        $attached = $this->blogPost($this->orgA, $this->ownerA, 'Attached article');
        $this->attach($dossier, $attached, $this->ownerA, 1);

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.articles.search', $dossier)
        );

        $response->assertOk();
        $titles = collect($response->json('articles'))->pluck('title')->all();
        $this->assertContains('Free article', $titles);
        $this->assertNotContains('Attached article', $titles);
    }

    public function test_search_excludes_articles_attached_as_series_root(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Search root test');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root article');
        $free = $this->blogPost($this->orgA, $this->ownerA, 'Free article');
        $this->attach($dossier, $root, $this->ownerA, 1);

        ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.articles.search', $dossier)
        );

        $response->assertOk();
        $titles = collect($response->json('articles'))->pluck('title')->all();
        $this->assertContains('Free article', $titles);
        $this->assertNotContains('Root article', $titles);
    }

    public function test_search_excludes_articles_attached_as_series_annex(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Search annex test');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex article');
        $free = $this->blogPost($this->orgA, $this->ownerA, 'Free article');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->orgA->id,
            'article_series_id' => $series->id,
            'blog_post_id' => $annex->id,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.articles.search', $dossier)
        );

        $response->assertOk();
        $titles = collect($response->json('articles'))->pluck('title')->all();
        $this->assertContains('Free article', $titles);
        $this->assertNotContains('Annex article', $titles);
    }

    public function test_search_excludes_articles_from_other_users(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Search user test');
        $mine = $this->blogPost($this->orgA, $this->ownerA, 'My article');
        $theirs = $this->blogPost($this->orgA, $this->editorA, 'Their article');

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.articles.search', $dossier)
        );

        $response->assertOk();
        $titles = collect($response->json('articles'))->pluck('title')->all();
        $this->assertContains('My article', $titles);
        $this->assertNotContains('Their article', $titles);
    }

    public function test_search_excludes_articles_from_other_organizations(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Search org test');
        $mine = $this->blogPost($this->orgA, $this->ownerA, 'Org A article');
        $otherOrg = $this->blogPost($this->orgB, $this->userB, 'Org B article');

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.articles.search', $dossier)
        );

        $response->assertOk();
        $titles = collect($response->json('articles'))->pluck('title')->all();
        $this->assertContains('Org A article', $titles);
        $this->assertNotContains('Org B article', $titles);
    }

    public function test_search_returns_empty_when_all_articles_attached(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Search empty test');
        $a1 = $this->blogPost($this->orgA, $this->ownerA, 'Attached one');
        $a2 = $this->blogPost($this->orgA, $this->ownerA, 'Attached two');
        $this->attach($dossier, $a1, $this->ownerA, 1);
        $this->attach($dossier, $a2, $this->ownerA, 2);

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.articles.search', $dossier)
        );

        $response->assertOk();
        $this->assertEmpty($response->json('articles'));
    }

    // --- Series CRUD ---

    public function test_owner_can_create_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->ownerA, 'Root article');
        $this->attach($dossier, $post, $this->ownerA, 1);

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertOk()->assertJsonStructure(['series' => ['id', 'root_blog_post_id'], 'message']);
        $this->assertDatabaseHas('article_series', ['root_blog_post_id' => $post->id, 'dossier_id' => $dossier->id]);
    }

    public function test_root_must_be_attached_to_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->ownerA, 'Not attached');

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertStatus(404);
    }

    public function test_article_cannot_be_root_of_two_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->ownerA, 'Root article');
        $this->attach($dossier, $post, $this->ownerA, 1);

        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        )->assertOk();

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['root_blog_post_id']);
    }

    public function test_cross_tenant_create_series_is_404(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgB, $this->userB, 'Other org');

        $response = $this->actingAs($this->userB)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertStatus(403);
    }

    public function test_reader_cannot_create_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->ownerA, 'Root article');
        $this->attach($dossier, $post, $this->ownerA, 1);

        DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'user_id' => $this->readerA->id,
            'role' => 'reader',
        ]);

        $response = $this->actingAs($this->readerA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertForbidden();
    }

    public function test_editor_can_create_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->ownerA, 'Root article');
        $this->attach($dossier, $post, $this->ownerA, 1);

        DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'user_id' => $this->editorA->id,
            'role' => 'editor',
        ]);

        $response = $this->actingAs($this->editorA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertOk();
    }

    public function test_stranger_cannot_create_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->ownerA, 'Root article');
        $this->attach($dossier, $post, $this->ownerA, 1);

        $response = $this->actingAs($this->strangerA)->postJson(
            $this->orgRoute('dossiers.series.store', $dossier),
            ['root_blog_post_id' => $post->id]
        );

        $response->assertForbidden();
    }

    public function test_show_series_returns_null_when_no_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.series.show', $dossier)
        );

        $response->assertOk()->assertJson(['series' => null]);
    }

    public function test_show_series_returns_series_data(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $this->attach($dossier, $root, $this->ownerA, 1);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.series.show', $dossier)
        );

        $response->assertOk()->assertJsonPath('series.id', $series->id);
    }

    // --- Annexes ---

    public function test_add_annex(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier),
            ['blog_post_id' => $annex->id]
        );

        $response->assertOk()->assertJsonStructure(['item' => ['id', 'blog_post_id'], 'message']);
        $this->assertDatabaseHas('article_series_items', ['blog_post_id' => $annex->id, 'article_series_id' => $series->id]);
    }

    public function test_annex_must_be_attached_to_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $orphan = $this->blogPost($this->orgA, $this->ownerA, 'Orphan');
        $this->attach($dossier, $root, $this->ownerA, 1);

        ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier),
            ['blog_post_id' => $orphan->id]
        );

        $response->assertStatus(404);
    }

    public function test_root_cannot_be_added_as_annex(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $this->attach($dossier, $root, $this->ownerA, 1);

        ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier),
            ['blog_post_id' => $root->id]
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['blog_post_id']);
    }

    public function test_article_cannot_be_annex_of_two_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root1 = $this->blogPost($this->orgA, $this->ownerA, 'Root 1');
        $root2 = $this->blogPost($this->orgA, $this->ownerA, 'Root 2');
        $shared = $this->blogPost($this->orgA, $this->ownerA, 'Shared');
        $this->attach($dossier, $root1, $this->ownerA, 1);
        $this->attach($dossier, $root2, $this->ownerA, 2);
        $this->attach($dossier, $shared, $this->ownerA, 3);

        $s1 = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root1->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->orgA->id,
            'article_series_id' => $s1->id,
            'blog_post_id' => $shared->id,
            'position' => 1,
        ]);

        $s2 = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root2->id,
        ]);

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier),
            ['blog_post_id' => $shared->id]
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['blog_post_id']);
    }

    public function test_show_series_json_path_uses_snake_case_for_relations(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Characterization — JSON show path');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root char');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex char');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->orgA->id,
            'article_series_id' => $series->id,
            'blog_post_id' => $annex->id,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->ownerA)->getJson(
            $this->orgRoute('dossiers.series.show', $dossier)
        );

        $response->assertOk();
        $json = $response->json();

        // Characterisation: the GET show endpoint returns snake_case keys
        $this->assertArrayHasKey('root_blog_post', $json['series'], 'rootBlogPost() → "root_blog_post" in JSON');
        $this->assertArrayHasKey('items', $json['series']);
        $this->assertCount(1, $json['series']['items']);
        $this->assertArrayHasKey('blog_post', $json['series']['items'][0], 'items[].blogPost() → "blog_post" in JSON');
        $this->assertEquals($annex->title, $json['series']['items'][0]['blog_post']['title']);
    }

    public function test_add_annex_json_response_uses_snake_case_blog_post(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Characterization — JSON annex path');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex characterization');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier),
            ['blog_post_id' => $annex->id]
        );

        $response->assertOk();
        $json = $response->json();

        // Characterisation: document the exact JSON path of the annex response
        // Laravel serialises the blogPost() relationship as snake_case 'blog_post'
        $this->assertArrayHasKey('item', $json);
        $this->assertArrayHasKey('blog_post', $json['item'], 'The annex relationship key is snake_case "blog_post", NOT camelCase "blogPost".');
        $this->assertArrayHasKey('blog_post_id', $json['item'], 'The FK column key is snake_case "blog_post_id".');
        $this->assertEquals($annex->title, $json['item']['blog_post']['title'], 'The title lives at item.blog_post.title.');
    }

    public function test_remove_annex_does_not_delete_blog_post(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->orgA->id,
            'article_series_id' => $series->id,
            'blog_post_id' => $annex->id,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.annexes.destroy', $dossier, ['item' => $annex->id])
        );

        $response->assertOk();
        $this->assertDatabaseMissing('article_series_items', ['blog_post_id' => $annex->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $annex->id]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $annex->id, 'dossier_id' => $dossier->id]);
    }

    public function test_remove_annex_does_not_detach_from_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->orgA->id,
            'article_series_id' => $series->id,
            'blog_post_id' => $annex->id,
            'position' => 1,
        ]);

        $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.annexes.destroy', $dossier, ['item' => $annex->id])
        );

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $annex->id, 'dossier_id' => $dossier->id]);
    }

    // --- Delete series (non-destructive) ---

    public function test_delete_series_does_not_delete_blog_posts(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Annex');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $this->orgA->id,
            'article_series_id' => $series->id,
            'blog_post_id' => $annex->id,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.destroy', $dossier)
        );

        $response->assertOk();
        $this->assertDatabaseMissing('article_series', ['id' => $series->id]);
        $this->assertDatabaseMissing('article_series_items', ['article_series_id' => $series->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $root->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $annex->id]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $root->id, 'dossier_id' => $dossier->id]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $annex->id, 'dossier_id' => $dossier->id]);
    }

    // --- Dossier soft-delete cleans series ---

    public function test_deleting_dossier_cleans_series_metadata(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $this->attach($dossier, $root, $this->ownerA, 1);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
        ]);

        $response = $this->actingAs($this->ownerA)->deleteJson(
            route('organization.dossiers.destroy', ['organization' => $this->orgA->slug, 'dossier' => $dossier->id])
        );

        $response->assertOk();
        $this->assertDatabaseMissing('article_series', ['id' => $series->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $root->id]);
    }

    // --- Stranger / cross-tenant ---

    public function test_stranger_cannot_manage_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $root = $this->blogPost($this->orgA, $this->ownerA, 'Root');
        $this->attach($dossier, $root, $this->ownerA, 1);

        $response = $this->actingAs($this->strangerA)->getJson(
            $this->orgRoute('dossiers.series.show', $dossier)
        );

        $response->assertForbidden();
    }

    public function test_cross_tenant_cannot_view_series(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $response = $this->actingAs($this->userB)->getJson(
            $this->orgRoute('dossiers.series.show', $dossier)
        );

        $response->assertStatus(403);
    }

    // ── Promotion de racine ─────────────────────────────────────────────────
    //
    // Le geste central des Series, et le seul qui deplace deux Articles a la
    // fois. Il n'avait aucun test.

    public function test_promoting_an_annex_makes_it_the_root(): void
    {
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk();

        $this->assertSame($annex->id, $series->fresh()->root_blog_post_id);
    }

    public function test_the_former_root_becomes_the_first_annex(): void
    {
        // Rien de ce qu'une personne a range dans la Serie n'en sort tout seul.
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk();

        $first = ArticleSeriesItem::where('article_series_id', $series->id)
            ->orderBy('position')
            ->first();

        $this->assertSame($root->id, $first->blog_post_id);
    }

    public function test_the_promoted_article_is_never_root_and_annex_at_once(): void
    {
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk();

        $this->assertFalse(
            ArticleSeriesItem::where('article_series_id', $series->id)
                ->where('blog_post_id', $annex->id)
                ->exists(),
        );
    }

    public function test_promoting_deletes_no_article(): void
    {
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();
        $before = BlogPost::count();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk();

        $this->assertSame($before, BlogPost::count());
        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $dossier->id, 'blog_post_id' => $root->id,
        ]);
    }

    public function test_an_article_of_another_dossier_can_never_become_root(): void
    {
        [$dossier, $series] = $this->seriesWithAnnex();
        $other = $this->dossier($this->orgA, $this->ownerA, 'Autre Dossier');
        $foreign = $this->blogPost($this->orgA, $this->ownerA, 'Ailleurs');
        $this->attach($other, $foreign, $this->ownerA, 1);

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $foreign->id,
            ])
            ->assertStatus(404);
    }

    // ── Reordonnancement ────────────────────────────────────────────────────

    public function test_reordering_changes_positions_and_no_title(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();
        $b = $this->blogPost($this->orgA, $this->ownerA, 'Deuxieme annexe');
        $this->attach($dossier, $b, $this->ownerA, 3);
        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $b->id]
        )->assertOk();

        $titlesBefore = BlogPost::orderBy('id')->pluck('title')->all();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.annexes.reorder', $dossier), [
                'items' => [$b->id, $a->id],
            ])
            ->assertOk();

        $order = ArticleSeriesItem::where('article_series_id', $series->id)
            ->orderBy('position')->pluck('blog_post_id')->all();

        $this->assertSame([$b->id, $a->id], $order);
        // Un classement ne renomme rien.
        $this->assertSame($titlesBefore, BlogPost::orderBy('id')->pluck('title')->all());
    }

    // ── Integrite sous concurrence ──────────────────────────────────────────

    public function test_adding_the_same_article_twice_never_creates_two_annexes(): void
    {
        [$dossier, $series] = $this->seriesWithAnnex();
        $c = $this->blogPost($this->orgA, $this->ownerA, 'Candidat');
        $this->attach($dossier, $c, $this->ownerA, 4);

        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $c->id]
        )->assertOk();

        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $c->id]
        )->assertStatus(422);

        $this->assertSame(1, ArticleSeriesItem::where('blog_post_id', $c->id)->count());
    }

    public function test_positions_stay_unique_and_contiguous_after_a_removal(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();

        foreach (['B', 'C'] as $n) {
            $p = $this->blogPost($this->orgA, $this->ownerA, "Annexe {$n}");
            $this->attach($dossier, $p, $this->ownerA, 9);
            $this->actingAs($this->ownerA)->postJson(
                $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $p->id]
            )->assertOk();
        }

        $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.annexes.destroy', $dossier, ['item' => $a->id])
        )->assertOk();

        $positions = ArticleSeriesItem::where('article_series_id', $series->id)
            ->orderBy('position')->pluck('position')->all();

        $this->assertSame(count($positions), count(array_unique($positions)));
        $this->assertSame(range(0, count($positions) - 1), $positions);
    }

    public function test_removing_an_annex_keeps_the_article_in_the_dossier(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();

        $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.annexes.destroy', $dossier, ['item' => $a->id])
        )->assertOk();

        $this->assertDatabaseHas('blog_posts', ['id' => $a->id]);
        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $dossier->id, 'blog_post_id' => $a->id,
        ]);
    }

    // ── Requetes forgees ────────────────────────────────────────────────────

    public function test_a_forged_series_id_of_another_organization_is_refused(): void
    {
        $foreignDossier = $this->dossier($this->orgB, $this->userB, 'Dossier etranger');
        $foreignRoot = $this->blogPost($this->orgB, $this->userB, 'Racine etrangere');
        $this->attach($foreignDossier, $foreignRoot, $this->userB, 1);

        ArticleSeries::create([
            'organization_id' => $this->orgB->id,
            'dossier_id' => $foreignDossier->id,
            'root_blog_post_id' => $foreignRoot->id,
            'created_by' => $this->userB->id,
        ]);

        $this->actingAs($this->ownerA)
            ->patchJson(route('organization.dossiers.series.update', [
                'organization' => $this->orgA->slug,
                'dossier' => $foreignDossier->id,
            ]), ['root_blog_post_id' => $foreignRoot->id])
            ->assertStatus(404);
    }

    /**
     * Une Serie avec sa racine et une annexe, prete a etre bousculee.
     *
     * @return array{0: Dossier, 1: ArticleSeries, 2: BlogPost, 3: BlogPost}
     */
    private function seriesWithAnnex(): array
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Serie de travail');

        $root = $this->blogPost($this->orgA, $this->ownerA, 'La racine');
        $annex = $this->blogPost($this->orgA, $this->ownerA, 'Premiere annexe');
        $this->attach($dossier, $root, $this->ownerA, 1);
        $this->attach($dossier, $annex, $this->ownerA, 2);

        $series = ArticleSeries::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->ownerA->id,
        ]);

        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier),
            ['blog_post_id' => $annex->id]
        )->assertOk();

        return [$dossier, $series, $root, $annex];
    }

    public function test_positions_stay_contiguous_after_a_promotion(): void
    {
        // La promotion incremente tout puis libere un rang : sans
        // renumerotation elle laissait des trous (0, 3, 4, 5). L'ordre etait
        // juste, mais deux mecaniques de numerotation dans le meme objet
        // finissent par se contredire.
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();

        foreach (['B', 'C'] as $n) {
            $p = $this->blogPost($this->orgA, $this->ownerA, "Annexe {$n}");
            $this->attach($dossier, $p, $this->ownerA, 9);
            $this->actingAs($this->ownerA)->postJson(
                $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $p->id]
            )->assertOk();
        }

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $a->id,
            ])
            ->assertOk();

        $positions = ArticleSeriesItem::where('article_series_id', $series->id)
            ->orderBy('position')->pluck('position')->all();

        $this->assertSame(range(0, count($positions) - 1), $positions);
    }

    // ── Concurrence : l'etat obsolete ───────────────────────────────────────
    //
    // Le harnais est mono-processus : une vraie concurrence parallele n'y est
    // pas praticable. Ces tests simulent donc l'etat concurrent — deux lectures
    // suivies de deux mutations — et verifient que la seconde ne casse aucun
    // invariant. C'est ce niveau-la qui est teste, pas davantage.

    public function test_two_successive_promotions_leave_exactly_one_root(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();
        $b = $this->blogPost($this->orgA, $this->ownerA, 'Troisieme');
        $this->attach($dossier, $b, $this->ownerA, 3);
        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $b->id]
        )->assertOk();

        foreach ([$a->id, $b->id] as $target) {
            $this->actingAs($this->ownerA)
                ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                    'root_blog_post_id' => $target,
                ])->assertOk();
        }

        $series->refresh();

        $this->assertSame($b->id, $series->root_blog_post_id);
        $this->assertFalse(
            ArticleSeriesItem::where('article_series_id', $series->id)
                ->where('blog_post_id', $series->root_blog_post_id)->exists(),
            'La racine ne doit jamais figurer aussi parmi les annexes.',
        );
        $this->assertSame(3, ArticleSeriesItem::where('article_series_id', $series->id)->count() + 1);
    }

    public function test_promoting_then_removing_keeps_every_article(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();
        $before = BlogPost::count();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $a->id,
            ])->assertOk();

        // L'ancienne racine, devenue premiere annexe, est retiree.
        $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.annexes.destroy', $dossier, ['item' => $root->id])
        )->assertOk();

        $this->assertSame($before, BlogPost::count());
        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $dossier->id, 'blog_post_id' => $root->id,
        ]);
        $this->assertSame($a->id, $series->fresh()->root_blog_post_id);
    }

    public function test_a_reorder_built_on_a_stale_list_is_refused(): void
    {
        // Deux personnes lisent la meme liste ; l'une retire une annexe, puis
        // l'autre envoie un classement qui la contient encore.
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();
        $b = $this->blogPost($this->orgA, $this->ownerA, 'Seconde annexe');
        $this->attach($dossier, $b, $this->ownerA, 3);
        $this->actingAs($this->ownerA)->postJson(
            $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $b->id]
        )->assertOk();

        $listeLue = [$a->id, $b->id];

        $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.annexes.destroy', $dossier, ['item' => $b->id])
        )->assertOk();

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.annexes.reorder', $dossier), [
                'items' => $listeLue,
            ])
            ->assertStatus(422);

        // L'annexe restante n'a pas bouge, et rien n'a ete a moitie ecrit.
        $this->assertSame(
            [$a->id],
            ArticleSeriesItem::where('article_series_id', $series->id)
                ->orderBy('position')->pluck('blog_post_id')->all(),
        );
    }

    public function test_dissolving_a_series_keeps_every_article_in_the_dossier(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();
        $before = BlogPost::count();

        $this->actingAs($this->ownerA)->deleteJson(
            $this->orgRoute('dossiers.series.destroy', $dossier)
        )->assertOk();

        $this->assertSame($before, BlogPost::count());
        $this->assertDatabaseMissing('article_series', ['id' => $series->id]);

        foreach ([$root->id, $a->id] as $id) {
            $this->assertDatabaseHas('dossier_blog_posts', [
                'dossier_id' => $dossier->id, 'blog_post_id' => $id,
            ]);
        }
    }

    // ── L'etat canonique renvoye au client (TASK-1094) ──────────────────────
    //
    // Le client applique cet etat au lieu de recharger la page. Il ne peut le
    // faire que si la reponse porte reellement la racine ET les annexes dans
    // l'ordre : c'est ce que ces tests fixent.

    public function test_promoting_returns_the_whole_series_not_just_the_root(): void
    {
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();

        $payload = $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk()
            ->json('series');

        $this->assertSame($annex->id, $payload['root_blog_post_id']);
        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('root_blog_post', $payload);
        $this->assertSame($annex->id, $payload['root_blog_post']['id']);
    }

    public function test_the_returned_annexes_carry_the_former_root_in_first_position(): void
    {
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();

        $items = $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk()
            ->json('series.items');

        $this->assertSame($root->id, $items[0]['blog_post_id']);
        $this->assertNotNull($items[0]['blog_post']['title'] ?? null);
    }

    public function test_the_returned_annexes_are_ordered_by_position(): void
    {
        [$dossier, $series, $root, $a] = $this->seriesWithAnnex();

        foreach (['B', 'C'] as $n) {
            $p = $this->blogPost($this->orgA, $this->ownerA, "Annexe {$n}");
            $this->attach($dossier, $p, $this->ownerA, 9);
            $this->actingAs($this->ownerA)->postJson(
                $this->orgRoute('dossiers.series.annexes.store', $dossier), ['blog_post_id' => $p->id]
            )->assertOk();
        }

        $items = $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $a->id,
            ])
            ->assertOk()
            ->json('series.items');

        $positions = array_column($items, 'position');

        $this->assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Les annexes doivent arriver dans l ordre.');
    }

    public function test_show_and_update_return_the_same_shape(): void
    {
        // Deux charges differentes pour le meme objet auraient fini par
        // diverger, et le client aurait applique un etat incomplet en croyant
        // l avoir recu entier.
        [$dossier, $series, $root, $annex] = $this->seriesWithAnnex();

        $viaShow = $this->actingAs($this->ownerA)
            ->getJson($this->orgRoute('dossiers.series.show', $dossier))
            ->assertOk()->json('series');

        $viaUpdate = $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.series.update', $dossier), [
                'root_blog_post_id' => $annex->id,
            ])
            ->assertOk()->json('series');

        $this->assertSame(array_keys($viaShow), array_keys($viaUpdate));
    }
}
