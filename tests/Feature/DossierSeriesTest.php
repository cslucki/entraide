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

        $response->assertRedirect();
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
}
