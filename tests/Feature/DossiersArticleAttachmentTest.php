<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DossiersArticleAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $authorA;

    private User $coauthorA;

    private User $memberA;

    private User $adminA;

    private User $authorB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'name' => 'Organisation A',
            'slug' => 'org-a',
            'is_active' => true,
        ]);
        $this->orgB = Organization::factory()->create([
            'name' => 'Organisation B',
            'slug' => 'org-b',
            'is_active' => true,
        ]);

        $this->authorA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->coauthorA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);
        $this->authorB = User::factory()->create(['organization_id' => $this->orgB->id]);
    }

    public function test_guest_is_redirected_from_dossier_detail(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');

        $this->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]))
            ->assertRedirect(route('login'));
    }

    public function test_owner_can_open_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');

        $this->actingAs($this->authorA)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]))
            ->assertOk()
            ->assertSee('Private folder');
    }

    public function test_non_owner_is_forbidden(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');

        $this->actingAs($this->memberA)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]))
            ->assertForbidden();
    }

    public function test_cross_organization_dossier_is_not_found(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');

        $this->actingAs($this->authorB)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgB, 'dossier' => $dossier->id]))
            ->assertNotFound();
    }

    public function test_author_attaches_owned_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Owned article');

        $this->actingAs($this->authorA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertRedirect(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]));

        $this->assertDatabaseHas('dossier_blog_posts', [
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->authorA->id,
            'position' => 1,
        ]);
    }

    public function test_coauthor_who_is_not_author_is_refused(): void
    {
        $dossier = $this->dossier($this->orgA, $this->coauthorA, 'Coauthor folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Coauthored article');
        $post->coAuthors()->attach($this->coauthorA->id, ['role' => 'coauthor', 'added_by' => $this->authorA->id]);

        $this->actingAs($this->coauthorA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('dossier_blog_posts', ['blog_post_id' => $post->id]);
    }

    public function test_member_without_blog_rights_is_refused(): void
    {
        $dossier = $this->dossier($this->orgA, $this->memberA, 'Member folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Other member article');

        $this->actingAs($this->memberA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertForbidden();
    }

    public function test_super_admin_non_author_has_no_bypass(): void
    {
        $dossier = $this->dossier($this->orgA, $this->adminA, 'Admin folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Admin cannot attach this');

        $this->actingAs($this->adminA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertForbidden();
    }

    public function test_cross_organization_article_is_not_found(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $post = $this->blogPost($this->orgB, $this->authorB, 'Other org article');

        $this->actingAs($this->authorA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertNotFound();
    }

    public function test_duplicate_attachment_is_rejected_cleanly(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Owned article');
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertSessionHasErrors('blog_post_id');
    }

    public function test_article_can_belong_to_only_one_dossier(): void
    {
        $first = $this->dossier($this->orgA, $this->authorA, 'First folder');
        $second = $this->dossier($this->orgA, $this->authorA, 'Second folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Owned article');
        $this->attach($first, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->orgA, 'dossier' => $second->id]), [
                'blog_post_id' => $post->id,
            ])
            ->assertSessionHasErrors('blog_post_id');

        $this->assertSame(1, DossierBlogPost::where('blog_post_id', $post->id)->count());
    }

    public function test_initial_order_and_reorder(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $first = $this->blogPost($this->orgA, $this->authorA, 'First article');
        $second = $this->blogPost($this->orgA, $this->authorA, 'Second article');

        $this->attach($dossier, $first, $this->authorA, 1);
        $this->attach($dossier, $second, $this->authorA, 2);

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $first->id, 'position' => 1]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $second->id, 'position' => 2]);

        $this->actingAs($this->authorA)
            ->patch(route('organization.dossiers.articles.reorder', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'articles' => [$second->id, $first->id],
            ])
            ->assertRedirect(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]));

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $second->id, 'position' => 1]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $first->id, 'position' => 2]);
    }

    public function test_reorder_is_limited_to_current_dossier(): void
    {
        $current = $this->dossier($this->orgA, $this->authorA, 'Current folder');
        $other = $this->dossier($this->orgA, $this->authorA, 'Other folder');
        $currentPost = $this->blogPost($this->orgA, $this->authorA, 'Current article');
        $otherPost = $this->blogPost($this->orgA, $this->authorA, 'Other article');
        $this->attach($current, $currentPost, $this->authorA, 1);
        $this->attach($other, $otherPost, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->patch(route('organization.dossiers.articles.reorder', ['organization' => $this->orgA, 'dossier' => $current->id]), [
                'articles' => [$otherPost->id, $currentPost->id],
            ])
            ->assertSessionHasErrors('articles');

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $currentPost->id, 'position' => 1]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $otherPost->id, 'position' => 1]);
    }

    public function test_detach_removes_only_pivot_and_keeps_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Owned article');
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->delete(route('organization.dossiers.articles.destroy', ['organization' => $this->orgA, 'dossier' => $dossier->id, 'post' => $post->id]))
            ->assertRedirect(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]));

        $this->assertDatabaseMissing('dossier_blog_posts', ['blog_post_id' => $post->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'deleted_at' => null]);
    }

    public function test_logical_dossier_delete_detaches_pivots_and_keeps_articles(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Owned article');
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->delete(route('organization.dossiers.destroy', ['organization' => $this->orgA, 'dossier' => $dossier->id]))
            ->assertRedirect(route('organization.dossiers.index', $this->orgA));

        $this->assertSoftDeleted('dossiers', ['id' => $dossier->id]);
        $this->assertDatabaseMissing('dossier_blog_posts', ['dossier_id' => $dossier->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'deleted_at' => null]);
    }

    public function test_soft_deleted_blog_post_is_hidden_from_dossier_page_but_pivot_remains(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Private folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Soft deleted article');
        $this->attach($dossier, $post, $this->authorA, 1);
        $post->delete();

        $this->actingAs($this->authorA)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $dossier->id]))
            ->assertOk()
            ->assertDontSee('Soft deleted article');

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $post->id]);
    }

    // ── createAndAttach gates ──────────────────────────────────────────

    public function test_owner_can_create_and_attach_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Owner dossier');

        $beforePostCount = BlogPost::where('organization_id', $this->orgA->id)->count();

        $this->actingAs($this->authorA)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'title' => 'New article from owner',
            ])
            ->assertCreated()
            ->assertJsonStructure(['message', 'post' => ['id', 'slug', 'title'], 'entry' => ['id', 'dossier_id', 'blog_post_id', 'position'], 'redirect_url']);

        $this->assertEquals($beforePostCount + 1, BlogPost::where('organization_id', $this->orgA->id)->count());
        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $dossier->id,
            'added_by' => $this->authorA->id,
            'position' => 1,
        ]);
    }

    public function test_editor_can_create_and_attach_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Editor dossier');
        DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'user_id' => $this->coauthorA->id,
            'role' => DossierMember::ROLE_EDITOR,
            'invited_by' => $this->authorA->id,
        ]);

        $this->actingAs($this->coauthorA)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'title' => 'Article from editor',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $dossier->id,
            'added_by' => $this->coauthorA->id,
        ]);
    }

    public function test_reader_cannot_create_and_attach_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Reader dossier');
        DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $dossier->id,
            'user_id' => $this->memberA->id,
            'role' => DossierMember::ROLE_READER,
            'invited_by' => $this->authorA->id,
        ]);

        $this->actingAs($this->memberA)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'title' => 'Reader should not create',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('blog_posts', ['title' => 'Reader should not create']);
    }

    public function test_cross_organization_user_cannot_create_and_attach(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Org A dossier');

        $this->actingAs($this->authorB)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgB, 'dossier' => $dossier->id]), [
                'title' => 'Cross org article',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('blog_posts', ['title' => 'Cross org article']);
    }

    public function test_create_and_attach_title_required_no_orphan_post(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Orphan test');

        $beforePostCount = BlogPost::where('organization_id', $this->orgA->id)->count();

        $this->actingAs($this->authorA)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'title' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->assertEquals($beforePostCount, BlogPost::where('organization_id', $this->orgA->id)->count());
        $this->assertDatabaseMissing('dossier_blog_posts', ['dossier_id' => $dossier->id]);
    }

    public function test_create_and_attach_invalid_category_no_orphan_post(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Category test');

        $fakeCategoryId = '00000000-0000-0000-0000-000000000000';
        $beforePostCount = BlogPost::where('organization_id', $this->orgA->id)->count();

        $this->actingAs($this->authorA)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'title' => 'Article with bad category',
                'category_id' => $fakeCategoryId,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->assertEquals($beforePostCount, BlogPost::where('organization_id', $this->orgA->id)->count());
        $this->assertDatabaseMissing('dossier_blog_posts', ['dossier_id' => $dossier->id]);
    }

    public function test_create_and_attach_redirect_url_is_org_scoped(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Redirect test');

        $response = $this->actingAs($this->authorA)
            ->postJson(route('organization.dossiers.articles.create-and-attach', ['organization' => $this->orgA, 'dossier' => $dossier->id]), [
                'title' => 'Redirect URL test',
            ])
            ->assertCreated();

        $redirectUrl = $response->json('redirect_url');
        $this->assertStringStartsWith("/org/{$this->orgA->slug}/blog/", $redirectUrl);
        $this->assertStringEndsWith('/edit', $redirectUrl);
    }

    // ── existing tests ──────────────────────────────────────────────

    public function test_root_route_and_forbidden_columns_are_absent(): void
    {
        $this->assertFalse(Route::has('dossiers.index'));
        $this->assertFalse(Schema::hasColumn('dossier_blog_posts', 'loop_id'));
        $this->assertFalse(Schema::hasColumn('dossier_blog_posts', 'community_id'));
        $this->assertFalse(Schema::hasColumn('dossier_blog_posts', 'role'));
        $this->assertFalse(Schema::hasColumn('dossier_blog_posts', 'root'));
        $this->assertFalse(Schema::hasColumn('dossier_blog_posts', 'annex'));

        $this->actingAs($this->authorA)
            ->get('/dossiers')
            ->assertNotFound();
    }

    private function dossier(Organization $organization, User $owner, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function blogPost(Organization $organization, User $author, string $title): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $author->id,
            'title' => $title,
            'content' => 'Article content for dossier attachment tests.',
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
}
