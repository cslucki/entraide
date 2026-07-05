<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogSnapshot;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class T962BlogSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    private function createOrganizations(): array
    {
        $organizationA = Organization::factory()->create(['is_active' => true]);
        $organizationB = Organization::factory()->create(['is_active' => true]);

        $organizationA->update(['is_default' => true]);

        return [$organizationA, $organizationB];
    }

    private function createUser(Organization $organization, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
        ], $overrides));
    }

    private function createPost(User $user, Organization $organization, array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'title' => 'Test article '.uniqid(),
            'content' => str_repeat('Contenu de test pour snapshot. ', 5),
            'status' => 'draft',
        ], $overrides));
    }

    private function validSnapshotData(): array
    {
        return [
            'name' => 'Version test '.uniqid(),
            'comment' => 'Commentaire de test',
            'title' => 'Titre snapshot',
            'summary' => 'Résumé snapshot',
            'content' => '<p>Contenu snapshot</p>',
            'meta_title' => 'Meta titre',
            'meta_description' => 'Meta description',
            'status' => 'draft',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 1. Owner can create a snapshot
    // ─────────────────────────────────────────────────────────────

    public function test_owner_can_create_snapshot(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $this->validSnapshotData())
            ->assertOk()
            ->assertJsonStructure(['id', 'name', 'created_at', 'message']);

        $this->assertDatabaseHas('blog_snapshots', [
            'blog_post_id' => $post->id,
            'created_by' => $user->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Name is required
    // ─────────────────────────────────────────────────────────────

    public function test_snapshot_name_is_required(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $data = $this->validSnapshotData();
        unset($data['name']);

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. Owner can list snapshots
    // ─────────────────────────────────────────────────────────────

    public function test_owner_can_list_snapshots(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => 'Version 1',
            'title' => 'Titre 1',
            'content' => '<p>Contenu 1</p>',
            'created_by' => $user->id,
        ]);

        BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => 'Version 2',
            'title' => 'Titre 2',
            'content' => '<p>Contenu 2</p>',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('blog.snapshots.index', $post))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Version 2')
            ->assertJsonPath('1.name', 'Version 1');
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Owner can restore a snapshot
    // ─────────────────────────────────────────────────────────────

    public function test_owner_can_restore_snapshot(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $snapshot = BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => 'Version à restaurer',
            'title' => 'Titre restaurant',
            'summary' => 'Résumé restaurant',
            'content' => '<p>Contenu restaurant</p>',
            'meta_title' => 'Meta restaurant',
            'meta_description' => 'Meta desc restaurant',
            'status' => 'published',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.restore', ['post' => $post, 'snapshot' => $snapshot]))
            ->assertOk()
            ->assertJson([
                'title' => 'Titre restaurant',
                'summary' => 'Résumé restaurant',
                'content' => '<p>Contenu restaurant</p>',
                'meta_title' => 'Meta restaurant',
                'meta_description' => 'Meta desc restaurant',
                'status' => 'published',
            ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 5. restored_at is set after restore
    // ─────────────────────────────────────────────────────────────

    public function test_restored_at_is_set_after_restore(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $snapshot = BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => 'Version à restaurer',
            'title' => 'Titre',
            'content' => '<p>Contenu</p>',
            'created_by' => $user->id,
        ]);

        $this->assertNull($snapshot->fresh()->restored_at);

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.restore', ['post' => $post, 'snapshot' => $snapshot]))
            ->assertOk();

        $this->assertNotNull($snapshot->fresh()->restored_at);
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Cross-org user cannot access snapshots
    // ─────────────────────────────────────────────────────────────

    public function test_cross_organization_user_cannot_access_snapshots(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();

        $userB = $this->createUser($organizationB);
        $postInOrgB = $this->createPost($userB, $organizationB);

        $snapshot = BlogSnapshot::create([
            'blog_post_id' => $postInOrgB->id,
            'name' => 'Version org B',
            'title' => 'Titre B',
            'content' => '<p>Contenu B</p>',
            'created_by' => $userB->id,
        ]);

        // User from org A (default) tries to access org B's snapshot — org check fails → 404
        $userA = $this->createUser($organizationA);

        $this->actingAs($userA)
            ->getJson(route('blog.snapshots.index', $postInOrgB))
            ->assertNotFound();

        $this->actingAs($userA)
            ->postJson(route('blog.snapshots.store', $postInOrgB), $this->validSnapshotData())
            ->assertNotFound();

        $this->actingAs($userA)
            ->postJson(route('blog.snapshots.restore', ['post' => $postInOrgB, 'snapshot' => $snapshot]))
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────
    // 7. Admin in same org can manage snapshots
    // ─────────────────────────────────────────────────────────────

    public function test_admin_can_manage_snapshots_in_own_org(): void
    {
        [$organization] = $this->createOrganizations();

        $author = $this->createUser($organization);
        $admin = $this->createUser($organization, ['is_admin' => true]);
        $post = $this->createPost($author, $organization);

        // Admin can create
        $this->actingAs($admin)
            ->postJson(route('blog.snapshots.store', $post), $this->validSnapshotData())
            ->assertOk();

        // Admin can list
        $this->actingAs($admin)
            ->getJson(route('blog.snapshots.index', $post))
            ->assertOk()
            ->assertJsonCount(1);

        // Admin can restore
        $snapshot = $post->snapshots()->first();
        $this->actingAs($admin)
            ->postJson(route('blog.snapshots.restore', ['post' => $post, 'snapshot' => $snapshot]))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // 8. Update article with active_snapshot_id updates snapshot
    // ─────────────────────────────────────────────────────────────

    public function test_update_with_active_snapshot_id_updates_snapshot(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization, ['status' => 'draft']);

        $snapshot = BlogSnapshot::create([
            'blog_post_id' => $post->id,
            'name' => 'Version initiale',
            'title' => 'Titre initial',
            'content' => '<p>Contenu initial</p>',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->put(route('blog.update', $post), [
                'title' => 'Titre mis à jour',
                'content' => '<p>Contenu mis à jour</p>',
                'status' => 'draft',
                'meta_title' => 'Meta mis à jour',
                'meta_description' => 'Meta desc mis à jour',
                'active_snapshot_id' => $snapshot->id,
            ])
            ->assertRedirect();

        $snapshot->refresh();

        $this->assertEquals('Titre mis à jour', $snapshot->title);
        $this->assertEquals('<p>Contenu mis à jour</p>', $snapshot->content);
        $this->assertEquals('Meta mis à jour', $snapshot->meta_title);
        $this->assertEquals('Meta desc mis à jour', $snapshot->meta_description);
        $this->assertEquals($user->id, $snapshot->updated_by);
    }

    // ─────────────────────────────────────────────────────────────
    // 9. Update without active_snapshot_id does not create snapshot
    // ─────────────────────────────────────────────────────────────

    public function test_update_without_active_snapshot_id_does_not_create_snapshot(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $snapshotsBefore = BlogSnapshot::where('blog_post_id', $post->id)->count();

        $this->actingAs($user)
            ->put(route('blog.update', $post), [
                'title' => 'Titre sans snapshot',
                'summary' => 'Résumé',
                'content' => '<p>Contenu</p>',
                'status' => 'draft',
            ]);

        $snapshotsAfter = BlogSnapshot::where('blog_post_id', $post->id)->count();

        $this->assertEquals($snapshotsBefore, $snapshotsAfter);
    }
}
