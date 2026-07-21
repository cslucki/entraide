<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DossierSharingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $readerA;

    private User $editorA;

    private User $strangerA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org A', 'slug' => 'org-a', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'Org B', 'slug' => 'org-b', 'is_active' => true]);

        $this->ownerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->readerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->editorA = User::factory()->create(['organization_id' => $this->orgA->id]);
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

    private function member(Dossier $dossier, User $user, string $role): DossierMember
    {
        $member = DossierMember::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'user_id' => $user->id,
            'role' => $role,
            'added_by' => $dossier->owner_id,
        ]);

        $dossier->syncVisibility();

        return $member;
    }

    private function orgRoute(string $name, Dossier $dossier, array $extra = []): string
    {
        return route("organization.{$name}", array_merge([
            'organization' => $this->orgA->slug,
            'dossier' => $dossier->id,
        ], $extra));
    }

    // --- Visibility ---

    public function test_dossier_becomes_shared_when_member_added(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->assertEquals(Dossier::VISIBILITY_PRIVATE, $dossier->visibility);

        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $dossier->refresh();
        $this->assertEquals(Dossier::VISIBILITY_SHARED, $dossier->visibility);
    }

    public function test_dossier_reverts_to_private_when_last_member_removed(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $m = $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);
        $dossier->refresh();
        $this->assertEquals(Dossier::VISIBILITY_SHARED, $dossier->visibility);

        $m->delete();
        $dossier->syncVisibility();
        $dossier->refresh();
        $this->assertEquals(Dossier::VISIBILITY_PRIVATE, $dossier->visibility);
    }

    public function test_is_member_returns_true_for_added_user(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->assertFalse($dossier->isMember($this->readerA->id));

        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);
        $this->assertTrue($dossier->isMember($this->readerA->id));
    }

    public function test_member_role_for_returns_role(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->assertNull($dossier->memberRoleFor($this->readerA->id));

        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);
        $this->assertEquals('reader', $dossier->memberRoleFor($this->readerA->id));
    }

    // --- Owner can manage members ---

    public function test_owner_can_list_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->ownerA)
            ->getJson($this->orgRoute('dossiers.members.index', $dossier))
            ->assertOk()
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.role', 'reader');
    }

    public function test_owner_can_add_member(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->readerA->id,
                'role' => 'reader',
            ])
            ->assertOk()
            ->assertJsonPath('member.role', 'reader');

        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $dossier->id,
            'user_id' => $this->readerA->id,
            'role' => 'reader',
        ]);
    }

    public function test_owner_can_update_member_role(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->ownerA)
            ->patchJson($this->orgRoute('dossiers.members.update', $dossier, ['member' => $this->readerA->id]), [
                'role' => 'editor',
            ])
            ->assertOk()
            ->assertJsonPath('member.role', 'editor');

        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $dossier->id,
            'user_id' => $this->readerA->id,
            'role' => 'editor',
        ]);
    }

    public function test_owner_can_remove_member(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->ownerA)
            ->deleteJson($this->orgRoute('dossiers.members.destroy', $dossier, ['member' => $this->readerA->id]))
            ->assertOk();

        $this->assertDatabaseMissing('dossier_members', [
            'dossier_id' => $dossier->id,
            'user_id' => $this->readerA->id,
        ]);

        $dossier->refresh();
        $this->assertEquals(Dossier::VISIBILITY_PRIVATE, $dossier->visibility);
    }

    public function test_owner_can_search_users(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->getJson($this->orgRoute('dossiers.members.search', $dossier, ['q' => $this->editorA->email]))
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.email', $this->editorA->email);
    }

    public function test_search_excludes_owner_and_existing_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->ownerA)
            ->getJson($this->orgRoute('dossiers.members.search', $dossier, ['q' => $this->editorA->email]))
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.email', $this->editorA->email);
    }

    public function test_reader_cannot_search_users(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->readerA)
            ->getJson($this->orgRoute('dossiers.members.search', $dossier, ['q' => $this->editorA->email]))
            ->assertForbidden();
    }

    public function test_editor_cannot_search_users(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->editorA, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->editorA)
            ->getJson($this->orgRoute('dossiers.members.search', $dossier, ['q' => $this->readerA->email]))
            ->assertForbidden();
    }

    public function test_stranger_cannot_search_users(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->strangerA)
            ->getJson($this->orgRoute('dossiers.members.search', $dossier, ['q' => $this->editorA->email]))
            ->assertForbidden();
    }

    // --- Non-owner cannot manage members ---

    public function test_reader_cannot_manage_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->readerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->editorA->id,
                'role' => 'reader',
            ])
            ->assertForbidden();
    }

    public function test_editor_cannot_manage_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->editorA, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->editorA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->readerA->id,
                'role' => 'reader',
            ])
            ->assertForbidden();
    }

    // --- Reader can view ---

    public function test_reader_can_list_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->readerA)
            ->getJson($this->orgRoute('dossiers.members.index', $dossier))
            ->assertOk();
    }

    // --- Email protection ---

    public function test_owner_sees_email_in_members_json(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $response = $this->actingAs($this->ownerA)
            ->getJson($this->orgRoute('dossiers.members.index', $dossier))
            ->assertOk();

        $this->assertNotNull($response->json('members.0.email'));
        $this->assertEquals($this->readerA->email, $response->json('members.0.email'));
    }

    public function test_editor_does_not_see_email_in_members_json(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->editorA, DossierMember::ROLE_EDITOR);
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $response = $this->actingAs($this->editorA)
            ->getJson($this->orgRoute('dossiers.members.index', $dossier))
            ->assertOk();

        $this->assertNull($response->json('members.0.email'));
        $this->assertNull($response->json('members.1.email'));
    }

    public function test_reader_does_not_see_email_in_members_json(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $response = $this->actingAs($this->readerA)
            ->getJson($this->orgRoute('dossiers.members.index', $dossier))
            ->assertOk();

        $this->assertNull($response->json('members.0.email'));
    }

    public function test_owner_sees_manage_modal_html(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->get($this->orgRoute('dossiers.show', $dossier))
            ->assertOk()
            ->assertSee('showManageModal')
            ->assertSee('manage-members-title');
    }

    public function test_editor_does_not_see_manage_modal_html(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->editorA, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->editorA)
            ->get($this->orgRoute('dossiers.show', $dossier))
            ->assertOk()
            ->assertDontSee('showManageModal')
            ->assertDontSee('manage-members-title');
    }

    public function test_reader_does_not_see_manage_modal_html(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->readerA)
            ->get($this->orgRoute('dossiers.show', $dossier))
            ->assertOk()
            ->assertDontSee('showManageModal')
            ->assertDontSee('manage-members-title');
    }

    // --- Validation ---

    public function test_cannot_add_owner_as_member(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->ownerA->id,
                'role' => 'reader',
            ])
            ->assertUnprocessable()
            ->assertJson(['message' => __('dossiers.member_is_owner')]);
    }

    public function test_cannot_add_same_member_twice(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->ownerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->readerA->id,
                'role' => 'reader',
            ])
            ->assertUnprocessable();
    }

    public function test_cannot_add_cross_org_user(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->userB->id,
                'role' => 'reader',
            ])
            ->assertUnprocessable();
    }

    public function test_invalid_role_rejected(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->readerA->id,
                'role' => 'admin',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    // --- Cross-tenant ---

    public function test_user_from_other_org_cannot_manage_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->userB)
            ->postJson(route('organization.dossiers.members.store', [
                'organization' => $this->orgA->slug,
                'dossier' => $dossier->id,
            ]), [
                'user_id' => $this->readerA->id,
                'role' => 'reader',
            ])
            ->assertForbidden();
    }

    // --- Stranger cannot access ---

    public function test_stranger_cannot_list_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->strangerA)
            ->getJson($this->orgRoute('dossiers.members.index', $dossier))
            ->assertForbidden();
    }

    public function test_stranger_cannot_add_member(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->strangerA)
            ->postJson($this->orgRoute('dossiers.members.store', $dossier), [
                'user_id' => $this->strangerA->id,
                'role' => 'reader',
            ])
            ->assertForbidden();
    }

    // --- Dossier show view role checks ---

    public function test_owner_sees_manage_members_section(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');

        $this->actingAs($this->ownerA)
            ->get($this->orgRoute('dossiers.show', $dossier))
            ->assertOk()
            ->assertSee(__('dossiers.members_title'))
            ->assertSee(__('dossiers.add_member'));
    }

    public function test_reader_does_not_see_manage_members_section(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->readerA)
            ->get($this->orgRoute('dossiers.show', $dossier))
            ->assertOk()
            ->assertDontSee(__('dossiers.add_member'))
            ->assertSee(__('dossiers.contents_tab'));
    }

    public function test_editor_can_see_attach_form(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'My folder');
        $this->member($dossier, $this->editorA, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->editorA)
            ->get($this->orgRoute('dossiers.show', $dossier))
            ->assertOk()
            ->assertSee(__('dossiers.contents_tab'))
            ->assertSee('dossierContentsCard');
    }

    // --- Shared dossiers index ---

    public function test_reader_sees_shared_dossiers_in_index(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Shared Folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->readerA)
            ->get(route('organization.dossiers.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee(__('dossiers.shared_with_me'))
            ->assertSee('Shared Folder');
    }

    public function test_owner_sees_shared_badge_when_dossier_has_members(): void
    {
        $dossier = $this->dossier($this->orgA, $this->ownerA, 'Shared Folder');
        $this->member($dossier, $this->readerA, DossierMember::ROLE_READER);

        $this->actingAs($this->ownerA)
            ->get(route('organization.dossiers.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee(__('dossiers.shared_badge'));
    }
}
