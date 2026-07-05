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
            ->assertJsonPath('snapshots', fn ($s) => count($s) === 2)
            ->assertJsonPath('snapshots.0.name', 'Version 2')
            ->assertJsonPath('snapshots.0.title', 'Titre 2')
            ->assertJsonPath('snapshots.0.content', '<p>Contenu 2</p>')
            ->assertJsonPath('snapshots.1.name', 'Version 1')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('total', 2);
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
            ->assertJsonPath('snapshots', fn ($s) => count($s) === 1);

        // Admin can restore
        $snapshot = $post->snapshots()->first();
        $this->actingAs($admin)
            ->postJson(route('blog.snapshots.restore', ['post' => $post, 'snapshot' => $snapshot]))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────
    // 8. Auto-snapshot created on article update when content changes
    // ─────────────────────────────────────────────────────────────

    public function test_auto_creates_snapshot_on_update_if_changed(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization, ['status' => 'draft']);

        $this->assertEquals(0, BlogSnapshot::where('blog_post_id', $post->id)->count());

        $this->actingAs($user)
            ->put(route('blog.update', $post), [
                'title' => 'Titre mis à jour',
                'content' => '<p>Contenu mis à jour</p>',
                'status' => 'draft',
            ])
            ->assertRedirect();

        $this->assertEquals(1, BlogSnapshot::where('blog_post_id', $post->id)->count());

        $snapshot = $post->snapshots()->first();
        $this->assertEquals('Titre mis à jour', $snapshot->title);
        $this->assertEquals('<p>Contenu mis à jour</p>', $snapshot->content);
        $this->assertStringStartsWith('Auto ', $snapshot->name);
        $this->assertEquals($user->id, $snapshot->created_by);
    }

    // ─────────────────────────────────────────────────────────────
    // 9. No auto-snapshot on update if content unchanged
    // ─────────────────────────────────────────────────────────────

    public function test_auto_does_not_create_snapshot_if_unchanged(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        // First save: create auto-snapshot with current content
        $this->actingAs($user)
            ->put(route('blog.update', $post), [
                'title' => $post->title,
                'content' => $post->content,
                'status' => 'draft',
            ]);

        $this->assertEquals(1, BlogSnapshot::where('blog_post_id', $post->id)->count());

        $post->refresh();

        // Second save with same content: no new snapshot
        $this->actingAs($user)
            ->put(route('blog.update', $post), [
                'title' => $post->title,
                'content' => $post->content,
                'status' => 'draft',
            ]);

        $this->assertEquals(1, BlogSnapshot::where('blog_post_id', $post->id)->count());
    }

    // ─────────────────────────────────────────────────────────────
    // 10. Manual snapshot with no changes updates name/comment
    // ─────────────────────────────────────────────────────────────

    public function test_manual_snapshot_updates_name_if_no_changes(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $data = $this->validSnapshotData();

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $data)
            ->assertOk();

        $this->assertEquals(1, BlogSnapshot::where('blog_post_id', $post->id)->count());

        $renamedData = $data;
        $renamedData['name'] = 'Nouveau nom '.uniqid();
        $renamedData['comment'] = 'Nouveau commentaire';

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $renamedData)
            ->assertOk()
            ->assertJsonPath('updated', true);

        $this->assertEquals(1, BlogSnapshot::where('blog_post_id', $post->id)->count());

        $snapshot = $post->snapshots()->first();
        $this->assertEquals($renamedData['name'], $snapshot->name);
        $this->assertEquals($renamedData['comment'], $snapshot->comment);
    }

    // ─────────────────────────────────────────────────────────────
    // 11. Manual snapshot with different content creates new
    // ─────────────────────────────────────────────────────────────

    public function test_manual_snapshot_creates_new_if_changes(): void
    {
        [$organization] = $this->createOrganizations();
        $user = $this->createUser($organization);
        $post = $this->createPost($user, $organization);

        $data = $this->validSnapshotData();

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $data)
            ->assertOk();

        $renamedData = $data;
        $renamedData['name'] = 'Version renommee '.uniqid();
        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $renamedData)
            ->assertOk()
            ->assertJsonPath('updated', true);

        $modifiedData = $data;
        $modifiedData['name'] = 'Version modifiée '.uniqid();
        $modifiedData['title'] = 'Titre modifié';
        $modifiedData['content'] = '<p>Contenu modifié</p>';

        $this->actingAs($user)
            ->postJson(route('blog.snapshots.store', $post), $modifiedData)
            ->assertOk();

        $this->assertEquals(2, BlogSnapshot::where('blog_post_id', $post->id)->count());
    }
}
