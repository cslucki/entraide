<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Like;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyBlogLikeTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $user;

    private BlogPost $post;

    private BlogPost $otherPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'test-org', 'is_active' => true]);
        $this->otherOrganization = Organization::factory()->create(['slug' => 'other-org', 'is_active' => true]);

        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->post = BlogPost::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'category_id' => null,
            'title' => 'Test Post',
            'slug' => 'test-post',
            'content' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit.',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->otherPost = BlogPost::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->otherOrganization->id,
            'category_id' => null,
            'title' => 'Other Org Post',
            'slug' => 'other-org-post',
            'content' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit.',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_user_can_like_post_in_own_organization(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('organization.likes.toggle', ['organization' => $this->organization->slug]), [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->post->id,
            ]);

        $response->assertOk();
        $response->assertJson(['liked' => true, 'count' => 1]);

        $this->assertDatabaseHas('likes', [
            'user_id' => $this->user->id,
            'likeable_id' => $this->post->id,
            'likeable_type' => BlogPost::class,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_like_has_organization_id_set(): void
    {
        $this->actingAs($this->user)
            ->post(route('organization.likes.toggle', ['organization' => $this->organization->slug]), [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->post->id,
            ]);

        $like = Like::where('user_id', $this->user->id)->first();

        $this->assertNotNull($like);
        $this->assertEquals($this->organization->id, $like->organization_id);
    }

    public function test_unlike_removes_like(): void
    {
        Like::create([
            'user_id' => $this->user->id,
            'likeable_id' => $this->post->id,
            'likeable_type' => BlogPost::class,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('organization.likes.toggle', ['organization' => $this->organization->slug]), [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->post->id,
            ]);

        $response->assertOk();
        $response->assertJson(['liked' => false, 'count' => 0]);

        $this->assertDatabaseMissing('likes', [
            'user_id' => $this->user->id,
            'likeable_id' => $this->post->id,
        ]);
    }

    public function test_unique_constraint_prevents_duplicate_likes(): void
    {
        Like::create([
            'user_id' => $this->user->id,
            'likeable_id' => $this->post->id,
            'likeable_type' => BlogPost::class,
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->user)
            ->post(route('organization.likes.toggle', ['organization' => $this->organization->slug]), [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->post->id,
            ]);

        $likes = Like::where('user_id', $this->user->id)
            ->where('likeable_id', $this->post->id)
            ->count();

        $this->assertEquals(0, $likes, 'Toggle twice removed the like, not created a duplicate');
    }

    public function test_like_count_is_scoped_to_organization(): void
    {
        $otherUser = User::factory()->create(['organization_id' => $this->organization->id]);

        Like::create([
            'user_id' => $this->user->id,
            'likeable_id' => $this->post->id,
            'likeable_type' => BlogPost::class,
            'organization_id' => $this->organization->id,
        ]);

        Like::create([
            'user_id' => $otherUser->id,
            'likeable_id' => $this->post->id,
            'likeable_type' => BlogPost::class,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('organization.likes.toggle', ['organization' => $this->organization->slug]), [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->post->id,
            ]);

        $response->assertJson(['count' => 1]);
    }

    public function test_cannot_like_post_from_other_organization(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('organization.likes.toggle', ['organization' => $this->organization->slug]), [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->otherPost->id,
            ]);

        $response->assertNotFound();
    }

    public function test_old_likes_toggle_route_no_longer_accessible(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/likes/toggle', [
                'likeable_type' => 'blog_post',
                'likeable_id' => $this->post->id,
            ]);

        $response->assertNotFound();
    }
}
