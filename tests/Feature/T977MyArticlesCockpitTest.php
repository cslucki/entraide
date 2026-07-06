<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class T977MyArticlesCockpitTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    private function createPost(User $user, array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'title' => 'Test Post',
            'content' => 'Test content for the blog post.',
            'status' => 'draft',
        ], $overrides));
    }

    private function getRoute(): string
    {
        return route('organization.blog.my-posts', ['organization' => $this->org->slug]);
    }

    // --- Page structure ---

    public function test_page_shows_all_tabs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get($this->getRoute());

        $response->assertOk();
        $response->assertSee(__('blog.tab_drafts'));
        $response->assertSee(__('blog.tab_published'));
        $response->assertSee(__('blog.tab_coauthors'));
        $response->assertSee(__('blog.tab_comments'));
    }

    // --- Co-authored tab ---

    public function test_coauthored_tab_shows_coauthored_posts(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner, ['title' => 'Co-authored Post']);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->actingAs($coAuthor);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee('Co-authored Post');
    }

    public function test_coauthored_tab_does_not_show_cross_org_posts(): void
    {
        $otherOrg = Organization::factory()->create();
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $crossPost = BlogPost::create([
            'user_id' => $owner->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Cross-org Post',
            'content' => 'Should not appear.',
            'status' => 'draft',
        ]);
        $crossPost->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->actingAs($coAuthor);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertDontSee('Cross-org Post');
    }

    // --- Role badges ---

    public function test_owned_post_shows_responsible_role(): void
    {
        $user = User::factory()->create();
        $this->createPost($user, ['title' => 'My Own Post']);

        $this->actingAs($user);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.role_responsible'));
    }

    public function test_coauthored_post_shows_coauthor_role(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner, ['title' => 'Co-authored Role Test']);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->actingAs($coAuthor);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.role_coauthor'));
    }

    // --- Delete button ---

    public function test_owner_sees_delete_button(): void
    {
        $user = User::factory()->create();
        $this->createPost($user, ['title' => 'Deletable Post']);

        $this->actingAs($user);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.btn_delete_post'));
    }

    public function test_admin_sees_delete_button(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->createPost($admin, ['title' => 'Admin Owned Post']);

        $this->actingAs($admin);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.btn_delete_post'));
    }

    public function test_coauthor_does_not_see_delete_button(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner, ['title' => 'No Delete Post']);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->actingAs($coAuthor);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertDontSee(__('blog.btn_delete_post'));
    }

    // --- Tab counts ---

    public function test_drafts_tab_shows_correct_count(): void
    {
        $user = User::factory()->create();
        $this->createPost($user, ['title' => 'Draft 1', 'status' => 'draft']);
        $this->createPost($user, ['title' => 'Draft 2', 'status' => 'draft']);
        $this->createPost($user, ['title' => 'Pending', 'status' => 'pending']);

        $this->actingAs($user);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.tab_drafts').' (3)');
    }

    public function test_published_tab_shows_correct_count(): void
    {
        $user = User::factory()->create();
        $this->createPost($user, ['title' => 'Pub 1', 'status' => 'published']);

        $this->actingAs($user);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.tab_published').' (1)');
    }

    public function test_coauthored_tab_shows_correct_count(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post1 = $this->createPost($owner, ['title' => 'Co 1']);
        $post2 = $this->createPost($owner, ['title' => 'Co 2']);
        $post1->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);
        $post2->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->actingAs($coAuthor);

        $response = $this->get($this->getRoute());
        $response->assertOk();
        $response->assertSee(__('blog.tab_coauthors').' (2)');
    }

    // --- Pagination ---

    public function test_pagination_uses_separate_page_names(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->createPost($user, ['title' => 'Draft '.($i + 1), 'status' => 'draft']);
        }

        for ($i = 0; $i < 20; $i++) {
            $this->createPost($user, ['title' => 'Pub '.($i + 1), 'status' => 'published']);
        }

        $this->actingAs($user);

        $response = $this->get($this->getRoute().'?drafts_page=1');
        $response->assertOk();
        $response->assertSee('Draft 1');

        $response2 = $this->get($this->getRoute().'?published_page=1');
        $response2->assertOk();
        $response2->assertSee('Pub 1');
    }
}
