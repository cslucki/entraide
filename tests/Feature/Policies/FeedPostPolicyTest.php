<?php

namespace Tests\Feature\Policies;

use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class FeedPostPolicyTest extends TestCase
{
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        app()->instance('current_organization', $this->org);
    }

    private function createPost(User $user, array $overrides = []): FeedPost
    {
        return FeedPost::create(array_merge([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT,
            'content' => 'Test announcement content.',
            'status' => FeedPost::STATUS_PUBLISHED,
        ], $overrides));
    }

    public function test_admin_can_create_post(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertTrue($admin->can('create', FeedPost::class));
    }

    public function test_org_admin_can_create_post(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $orgAdmin->id]);
        $this->assertTrue($orgAdmin->can('create', FeedPost::class));
    }

    public function test_member_cannot_create_post_in_admin_mode(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($member->can('create', FeedPost::class));
    }

    public function test_member_can_create_post_in_members_mode(): void
    {
        $this->org->update(['feed_post_publish_mode' => 'members']);
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertTrue($member->can('create', FeedPost::class));
    }

    public function test_cannot_create_without_organization(): void
    {
        app()->forgetInstance('current_organization');
        $user = User::factory()->create();
        $this->assertFalse($user->can('create', FeedPost::class));
    }

    public function test_cross_org_member_cannot_create(): void
    {
        $otherOrg = Organization::factory()->create();
        $member = User::factory()->create(['organization_id' => $otherOrg->id]);
        $this->assertFalse($member->can('create', FeedPost::class));
    }

    public function test_member_can_view_any(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertTrue($member->can('viewAny', FeedPost::class));
    }

    public function test_cross_org_cannot_view_any(): void
    {
        $otherOrg = Organization::factory()->create();
        $member = User::factory()->create(['organization_id' => $otherOrg->id]);
        $this->assertFalse($member->can('viewAny', FeedPost::class));
    }

    public function test_cannot_view_any_without_organization(): void
    {
        app()->forgetInstance('current_organization');
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($member->can('viewAny', FeedPost::class));
    }

    public function test_member_can_view_post(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($member);
        $this->assertTrue($member->can('view', $post));
    }

    public function test_cross_org_cannot_view_post(): void
    {
        $otherOrg = Organization::factory()->create();
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($member, ['organization_id' => $otherOrg->id]);
        $this->assertFalse($member->can('view', $post));
    }

    public function test_member_can_react_to_post(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($member);
        $this->assertTrue($member->can('react', $post));
    }

    public function test_member_can_comment_on_post(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($member);
        $this->assertTrue($member->can('comment', $post));
    }

    public function test_owner_can_update_post(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($owner);
        $this->assertTrue($owner->can('update', $post));
    }

    public function test_non_owner_cannot_update_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($owner);
        $this->assertFalse($other->can('update', $post));
    }

    public function test_admin_can_update_any_post(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);
        $this->assertTrue($admin->can('update', $post));
    }

    public function test_owner_can_delete_post(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($owner);
        $this->assertTrue($owner->can('delete', $post));
    }

    public function test_non_owner_cannot_delete_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post = $this->createPost($owner);
        $this->assertFalse($other->can('delete', $post));
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
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($owner, ['organization_id' => $otherOrg->id]);
        $this->assertFalse($owner->can('update', $post));
        $this->assertFalse($owner->can('delete', $post));
    }

    public function test_admin_does_not_bypass_organization_check_for_update_and_delete(): void
    {
        $otherOrg = Organization::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner, ['organization_id' => $otherOrg->id]);
        $this->assertFalse($admin->can('update', $post));
        $this->assertFalse($admin->can('delete', $post));
    }

    public function test_no_organization_resolved_denied(): void
    {
        app()->forgetInstance('current_organization');
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $post = $this->createPost($owner);
        $this->assertFalse($owner->can('update', $post));
        $this->assertFalse($owner->can('delete', $post));
    }

    public function test_banned_user_cannot_create_post(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $this->assertFalse($user->can('create', FeedPost::class));
    }

    public function test_banned_user_cannot_view_any(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $this->assertFalse($user->can('viewAny', FeedPost::class));
    }

    public function test_banned_user_cannot_view_post(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $post = $this->createPost($user);
        $this->assertFalse($user->can('view', $post));
    }

    public function test_banned_user_cannot_react(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $post = $this->createPost($user);
        $this->assertFalse($user->can('react', $post));
    }

    public function test_banned_user_cannot_comment(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $post = $this->createPost($user);
        $this->assertFalse($user->can('comment', $post));
    }

    public function test_banned_user_cannot_update_post(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $post = $this->createPost($user);
        $this->assertFalse($user->can('update', $post));
    }

    public function test_banned_user_cannot_delete_post(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'banned_at' => now()]);
        $post = $this->createPost($user);
        $this->assertFalse($user->can('delete', $post));
    }

    public function test_org_admin_can_pin(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $orgAdmin->id]);
        $this->assertTrue($orgAdmin->can('pin', FeedPost::class));
    }

    public function test_global_admin_can_pin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertTrue($admin->can('pin', FeedPost::class));
    }

    public function test_member_cannot_pin(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($member->can('pin', FeedPost::class));
    }

    public function test_banned_user_cannot_pin(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'banned_at' => now()]);
        $this->assertFalse($admin->can('pin', FeedPost::class));
    }

    public function test_cross_org_cannot_pin(): void
    {
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $otherOrg->id, 'is_admin' => true]);
        $this->assertFalse($admin->can('pin', FeedPost::class));
    }

    public function test_org_admin_can_update_any_post(): void
    {
        $owner = User::factory()->create();
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $orgAdmin->id]);
        $post = $this->createPost($owner);
        $this->assertTrue($orgAdmin->can('update', $post));
    }

    public function test_org_admin_can_delete_any_post(): void
    {
        $owner = User::factory()->create();
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $orgAdmin->id]);
        $post = $this->createPost($owner);
        $this->assertTrue($orgAdmin->can('delete', $post));
    }
}
