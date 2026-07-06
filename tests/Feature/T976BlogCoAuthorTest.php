<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class T976BlogCoAuthorTest extends TestCase
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

    // --- Policy: update ---

    public function test_co_author_can_update_post(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->assertTrue($coAuthor->can('update', $post));
    }

    public function test_non_owner_non_coauthor_cannot_update_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($owner);

        $this->assertFalse($other->can('update', $post));
    }

    public function test_co_author_cannot_delete_post(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->assertFalse($coAuthor->can('delete', $post));
    }

    // --- Policy: manageCoAuthors ---

    public function test_owner_can_manage_co_authors(): void
    {
        $owner = User::factory()->create();
        $post = $this->createPost($owner);

        $this->assertTrue($owner->can('manageCoAuthors', $post));
    }

    public function test_admin_can_manage_co_authors(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);

        $this->assertTrue($admin->can('manageCoAuthors', $post));
    }

    public function test_co_author_cannot_manage_co_authors(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $this->assertFalse($coAuthor->can('manageCoAuthors', $post));
    }

    public function test_non_owner_cannot_manage_co_authors(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($owner);

        $this->assertFalse($other->can('manageCoAuthors', $post));
    }

    // --- Policy: cross-org ---

    public function test_cross_organization_user_cannot_update_co_author_post(): void
    {
        $otherOrg = Organization::factory()->create();
        $owner = User::factory()->create();
        $crossUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($crossUser->id, ['role' => 'coauthor']);

        app()->instance('current_organization', $otherOrg);
        $this->assertFalse($crossUser->can('update', $post));
    }

    // --- HTTP: index ---

    public function test_owner_can_list_co_authors(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $response = $this->actingAs($owner)->getJson("/blog/{$post->slug}/co-authors");

        $response->assertOk();
        $response->assertJsonCount(1, 'co_authors');
    }

    public function test_co_author_can_list_co_authors(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $response = $this->actingAs($coAuthor)->getJson("/blog/{$post->slug}/co-authors");

        $response->assertOk();
    }

    // --- HTTP: store ---

    public function test_owner_can_add_co_author(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);

        $response = $this->actingAs($owner)->postJson("/blog/{$post->slug}/co-authors", [
            'user_id' => $coAuthor->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('blog_post_user', [
            'blog_post_id' => $post->id,
            'user_id' => $coAuthor->id,
        ]);
    }

    public function test_co_author_cannot_add_co_author(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $thirdUser = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $response = $this->actingAs($coAuthor)->postJson("/blog/{$post->slug}/co-authors", [
            'user_id' => $thirdUser->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_add_cross_org_user(): void
    {
        $owner = User::factory()->create();
        $otherOrg = Organization::factory()->create();
        $crossUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $post = $this->createPost($owner);

        $response = $this->actingAs($owner)->postJson("/blog/{$post->slug}/co-authors", [
            'user_id' => $crossUser->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', __('blog.co_author_cross_org'));
    }

    public function test_cannot_add_owner_as_co_author(): void
    {
        $owner = User::factory()->create();
        $post = $this->createPost($owner);

        $response = $this->actingAs($owner)->postJson("/blog/{$post->slug}/co-authors", [
            'user_id' => $owner->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_add_duplicate_co_author(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $response = $this->actingAs($owner)->postJson("/blog/{$post->slug}/co-authors", [
            'user_id' => $coAuthor->id,
        ]);

        $response->assertStatus(422);
    }

    // --- HTTP: destroy ---

    public function test_owner_can_remove_co_author(): void
    {
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $response = $this->actingAs($owner)->deleteJson("/blog/{$post->slug}/co-authors/{$coAuthor->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('blog_post_user', [
            'blog_post_id' => $post->id,
            'user_id' => $coAuthor->id,
        ]);
    }

    public function test_co_author_cannot_remove_co_author(): void
    {
        $owner = User::factory()->create();
        $coAuthor1 = User::factory()->create();
        $coAuthor2 = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor1->id, ['role' => 'coauthor']);
        $post->coAuthors()->attach($coAuthor2->id, ['role' => 'coauthor']);

        $response = $this->actingAs($coAuthor1)->deleteJson("/blog/{$post->slug}/co-authors/{$coAuthor2->id}");

        $response->assertStatus(403);
    }

    // --- HTTP: admin ---

    public function test_admin_can_add_co_author(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);

        $response = $this->actingAs($admin)->postJson("/blog/{$post->slug}/co-authors", [
            'user_id' => $coAuthor->id,
        ]);

        $response->assertOk();
    }

    public function test_admin_can_remove_co_author(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $coAuthor = User::factory()->create();
        $post = $this->createPost($owner);
        $post->coAuthors()->attach($coAuthor->id, ['role' => 'coauthor']);

        $response = $this->actingAs($admin)->deleteJson("/blog/{$post->slug}/co-authors/{$coAuthor->id}");

        $response->assertOk();
    }

    // --- HTTP: unauthorized ---

    public function test_guest_cannot_access_co_authors(): void
    {
        $owner = User::factory()->create();
        $post = $this->createPost($owner);

        $response = $this->getJson("/blog/{$post->slug}/co-authors");

        $response->assertUnauthorized();
    }
}
