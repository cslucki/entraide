<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogDossierCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $authorA;

    private User $coauthorA;

    private User $memberA;

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
        $this->authorB = User::factory()->create(['organization_id' => $this->orgB->id]);
    }

    // ─── listDossiers ───

    public function test_guest_cannot_list_dossiers(): void
    {
        $this->getJson(route('blog.dossiers.index'))
            ->assertUnauthorized();
    }

    public function test_author_can_list_own_dossiers(): void
    {
        $this->dossier($this->orgA, $this->authorA, 'Alpha');
        $this->dossier($this->orgA, $this->authorA, 'Beta');

        $this->actingAs($this->authorA)
            ->getJson(route('blog.dossiers.index'))
            ->assertOk()
            ->assertJsonCount(2, 'dossiers')
            ->assertJsonPath('dossiers.0.name', 'Alpha')
            ->assertJsonPath('dossiers.1.name', 'Beta');
    }

    public function test_author_does_not_see_other_users_dossiers(): void
    {
        $this->dossier($this->orgA, $this->coauthorA, 'Coauthor folder');

        $this->actingAs($this->authorA)
            ->getJson(route('blog.dossiers.index'))
            ->assertOk()
            ->assertJsonCount(0, 'dossiers');
    }

    // ─── currentDossier ───

    public function test_current_dossier_returns_null_when_not_attached(): void
    {
        $post = $this->blogPost($this->orgA, $this->authorA, 'Free article');

        $this->actingAs($this->authorA)
            ->getJson(route('blog.dossier.current', $post->slug))
            ->assertOk()
            ->assertJson(['dossier' => null]);
    }

    public function test_current_dossier_returns_dossier_info(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Classified article');
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->getJson(route('blog.dossier.current', $post->slug))
            ->assertOk()
            ->assertJsonPath('dossier.id', $dossier->id)
            ->assertJsonPath('dossier.name', 'My folder');
    }

    public function test_coauthor_can_read_current_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Author folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Coauthored article');
        $post->coAuthors()->attach($this->coauthorA->id, ['role' => 'coauthor', 'added_by' => $this->authorA->id]);
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->coauthorA)
            ->getJson(route('blog.dossier.current', $post->slug))
            ->assertOk()
            ->assertJsonPath('dossier.name', 'Author folder');
    }

    // ─── attach (classify) ───

    public function test_author_classifies_owned_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'My folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'My article');

        $this->actingAs($this->authorA)
            ->postJson(route('blog.dossier.attach', $post->slug), ['dossier_id' => $dossier->id])
            ->assertOk()
            ->assertJsonPath('dossier.name', 'My folder');

        $this->assertDatabaseHas('dossier_blog_posts', [
            'blog_post_id' => $post->id,
            'dossier_id' => $dossier->id,
        ]);
    }

    public function test_coauthor_cannot_classify(): void
    {
        $dossier = $this->dossier($this->orgA, $this->coauthorA, 'Coauthor folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Author article');
        $post->coAuthors()->attach($this->coauthorA->id, ['role' => 'coauthor', 'added_by' => $this->authorA->id]);

        $this->actingAs($this->coauthorA)
            ->postJson(route('blog.dossier.attach', $post->slug), ['dossier_id' => $dossier->id])
            ->assertStatus(403);

        $this->assertDatabaseMissing('dossier_blog_posts', ['blog_post_id' => $post->id]);
    }

    public function test_member_without_blog_rights_cannot_classify(): void
    {
        $dossier = $this->dossier($this->orgA, $this->memberA, 'Member folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Author article');

        $this->actingAs($this->memberA)
            ->postJson(route('blog.dossier.attach', $post->slug), ['dossier_id' => $dossier->id])
            ->assertForbidden();
    }

    public function test_cross_tenant_classify_is_not_found(): void
    {
        $dossier = $this->dossier($this->orgB, $this->authorB, 'B folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'A article');

        $this->actingAs($this->authorA)
            ->postJson(route('blog.dossier.attach', $post->slug), ['dossier_id' => $dossier->id])
            ->assertNotFound();
    }

    public function test_already_attached_returns_422(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Article');
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->postJson(route('blog.dossier.attach', $post->slug), ['dossier_id' => $dossier->id])
            ->assertStatus(422);
    }

    // ─── move (classify to different dossier) ───

    public function test_author_moves_article_to_different_dossier(): void
    {
        $first = $this->dossier($this->orgA, $this->authorA, 'First');
        $second = $this->dossier($this->orgA, $this->authorA, 'Second');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Movable article');
        $this->attach($first, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->deleteJson(route('blog.dossier.detach', $post->slug))
            ->assertOk();

        $this->actingAs($this->authorA)
            ->postJson(route('blog.dossier.attach', $post->slug), ['dossier_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('dossier.name', 'Second');

        $this->assertDatabaseMissing('dossier_blog_posts', ['dossier_id' => $first->id, 'blog_post_id' => $post->id]);
        $this->assertDatabaseHas('dossier_blog_posts', ['dossier_id' => $second->id, 'blog_post_id' => $post->id]);
    }

    // ─── detach ───

    public function test_author_detaches_article(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Attached article');
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->authorA)
            ->deleteJson(route('blog.dossier.detach', $post->slug))
            ->assertOk();

        $this->assertDatabaseMissing('dossier_blog_posts', ['blog_post_id' => $post->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'deleted_at' => null]);
    }

    public function test_detach_not_attached_returns_422(): void
    {
        $post = $this->blogPost($this->orgA, $this->authorA, 'Free article');

        $this->actingAs($this->authorA)
            ->deleteJson(route('blog.dossier.detach', $post->slug))
            ->assertStatus(422);
    }

    public function test_coauthor_cannot_detach(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Article');
        $post->coAuthors()->attach($this->coauthorA->id, ['role' => 'coauthor', 'added_by' => $this->authorA->id]);
        $this->attach($dossier, $post, $this->authorA, 1);

        $this->actingAs($this->coauthorA)
            ->deleteJson(route('blog.dossier.detach', $post->slug))
            ->assertStatus(403);

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $post->id]);
    }

    // ─── quickCreate ───

    public function test_author_quick_creates_dossier(): void
    {
        $this->actingAs($this->authorA)
            ->postJson(route('blog.dossiers.store'), ['name' => 'Quick Folder'])
            ->assertCreated()
            ->assertJsonPath('dossier.name', 'Quick Folder');

        $this->assertDatabaseHas('dossiers', [
            'name' => 'Quick Folder',
            'owner_id' => $this->authorA->id,
            'organization_id' => $this->orgA->id,
        ]);
    }

    public function test_quick_create_empty_name_fails(): void
    {
        $this->actingAs($this->authorA)
            ->postJson(route('blog.dossiers.store'), ['name' => ''])
            ->assertStatus(422);
    }

    // ─── org-scoped variants ───

    public function test_org_scoped_list_dossiers(): void
    {
        $this->dossier($this->orgA, $this->authorA, 'Org scoped');

        $this->actingAs($this->authorA)
            ->getJson(route('organization.blog.dossiers.index', ['organization' => $this->orgA]))
            ->assertOk()
            ->assertJsonCount(1, 'dossiers');
    }

    public function test_org_scoped_classify(): void
    {
        $dossier = $this->dossier($this->orgA, $this->authorA, 'Org folder');
        $post = $this->blogPost($this->orgA, $this->authorA, 'Org article');

        $this->actingAs($this->authorA)
            ->postJson(route('organization.blog.dossier.attach', ['organization' => $this->orgA, 'post' => $post->slug]), ['dossier_id' => $dossier->id])
            ->assertOk();

        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $post->id]);
    }

    public function test_org_scoped_quick_create(): void
    {
        $this->actingAs($this->authorA)
            ->postJson(route('organization.blog.dossiers.store', ['organization' => $this->orgA]), ['name' => 'Org Quick'])
            ->assertCreated()
            ->assertJsonPath('dossier.name', 'Org Quick');
    }

    // ─── helpers ───

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
            'content' => 'Test content for blog dossier card tests.',
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
