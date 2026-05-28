<?php

namespace Tests\Feature\Policies;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class BlogPostPolicyTest extends TestCase
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

    public function test_non_banned_user_can_create_post(): void
    {
        $user = User::factory()->create(['banned_at' => null]);
        $this->assertTrue($user->can('create', BlogPost::class));
    }

    public function test_banned_user_cannot_create_post(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);
        $this->assertFalse($user->can('create', BlogPost::class));
    }

    public function test_non_banned_user_cannot_create_without_organization(): void
    {
        app()->forgetInstance('current_organization');
        $user = User::factory()->create(['banned_at' => null]);
        $this->assertFalse($user->can('create', BlogPost::class));
    }

    public function test_owner_can_update_post(): void
    {
        $user = User::factory()->create();
        $post = $this->createPost($user);
        $this->assertTrue($user->can('update', $post));
    }

    public function test_non_owner_cannot_update_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($owner);
        $this->assertFalse($other->can('update', $post));
    }

    public function test_owner_can_delete_post(): void
    {
        $user = User::factory()->create();
        $post = $this->createPost($user);
        $this->assertTrue($user->can('delete', $post));
    }

    public function test_non_owner_cannot_delete_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($owner);
        $this->assertFalse($other->can('delete', $post));
    }

    public function test_admin_can_update_any_post(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);
        $this->assertTrue($admin->can('update', $post));
    }

    public function test_admin_can_delete_any_post(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);
        $this->assertTrue($admin->can('delete', $post));
    }

    public function test_cross_organization_denied(): void
    {
        $otherOrg = Organization::factory()->create();
        $user = User::factory()->create();
        $post = $this->createPost($user, ['organization_id' => $otherOrg->id]);
        $this->assertFalse($user->can('update', $post));
        $this->assertFalse($user->can('delete', $post));
    }

    public function test_admin_bypasses_organization_check(): void
    {
        $otherOrg = Organization::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner, ['organization_id' => $otherOrg->id]);
        $this->assertTrue($admin->can('update', $post));
        $this->assertTrue($admin->can('delete', $post));
    }

    public function test_no_organization_resolved_denied_for_non_admin(): void
    {
        app()->forgetInstance('current_organization');
        $user = User::factory()->create();
        $post = $this->createPost($user);
        $this->assertFalse($user->can('update', $post));
        $this->assertFalse($user->can('delete', $post));
    }
}
