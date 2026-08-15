<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK1141SharingPanelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $member;

    private Dossier $personalRoot;

    private Dossier $parent;

    private Dossier $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'Main',
            'slug' => 'main',
            'is_active' => true,
        ]);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->personalRoot = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Mes documents',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
            'system_role' => Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS,
        ]);
        $this->parent = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->personalRoot->id,
            'owner_id' => null,
            'name' => 'Dossier de main member 3',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->child = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->parent->id,
            'owner_id' => null,
            'name' => 'Sous-dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    public function test_searching_and_selecting_a_candidate_does_not_create_membership_or_permissions(): void
    {
        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.search', $this->parent, ['q' => $this->member->email]))
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->member->id);

        $this->assertDatabaseMissing('dossier_members', [
            'dossier_id' => $this->parent->id,
            'user_id' => $this->member->id,
        ]);

        $this->actingAs($this->member)
            ->get($this->route('dossiers.show', $this->parent))
            ->assertForbidden();

        $this->assertFalse($this->member->can('update', $this->parent));
    }

    public function test_explicit_addition_always_starts_as_reader(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.members.store', $this->parent), [
                'user_id' => $this->member->id,
                // Même si un ancien client tente encore de présélectionner
                // editor, le serveur crée d'abord un accès reader.
                'role' => DossierMember::ROLE_EDITOR,
            ])
            ->assertOk()
            ->assertJsonPath('member.role', DossierMember::ROLE_READER);

        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $this->parent->id,
            'user_id' => $this->member->id,
            'role' => DossierMember::ROLE_READER,
        ]);
    }

    public function test_reader_editor_reader_changes_are_persisted(): void
    {
        $this->share($this->parent, DossierMember::ROLE_READER);

        $this->actingAs($this->owner)
            ->patchJson($this->route('dossiers.members.update', $this->parent, ['member' => $this->member->id]), ['role' => 'editor'])
            ->assertOk()
            ->assertJsonPath('message', __('dossiers.member_role_updated'));

        $this->assertDatabaseHas('dossier_members', ['dossier_id' => $this->parent->id, 'user_id' => $this->member->id, 'role' => 'editor']);

        $this->actingAs($this->owner)
            ->patchJson($this->route('dossiers.members.update', $this->parent, ['member' => $this->member->id]), ['role' => 'reader'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.index', $this->parent))
            ->assertOk()
            ->assertJsonPath('members.0.role', 'reader');
    }

    public function test_child_panel_returns_inherited_access_without_creating_child_membership(): void
    {
        $this->share($this->parent, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.index', $this->child))
            ->assertOk()
            ->assertJsonCount(0, 'members')
            ->assertJsonCount(1, 'inherited_members')
            ->assertJsonPath('inherited_members.0.id', $this->member->id)
            ->assertJsonPath('inherited_members.0.role', 'editor')
            ->assertJsonPath('inherited_members.0.inherited_from.id', $this->parent->id)
            ->assertJsonPath('inherited_members.0.inherited_from.name', $this->parent->name);

        $this->assertDatabaseMissing('dossier_members', [
            'dossier_id' => $this->child->id,
            'user_id' => $this->member->id,
        ]);
        $this->actingAs($this->member)->get($this->route('dossiers.show', $this->child))->assertOk();
        $this->assertTrue($this->member->can('update', $this->child));
    }

    public function test_removing_parent_share_removes_inheritance(): void
    {
        $this->share($this->parent, DossierMember::ROLE_READER);

        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.members.destroy', $this->parent, ['member' => $this->member->id]))
            ->assertOk();

        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.index', $this->child))
            ->assertOk()
            ->assertJsonCount(0, 'inherited_members');
        $this->actingAs($this->member)->get($this->route('dossiers.show', $this->child))->assertForbidden();
    }

    public function test_explicit_child_anchor_survives_parent_share_removal(): void
    {
        $this->share($this->parent, DossierMember::ROLE_READER);
        $this->share($this->child, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->owner)
            ->deleteJson($this->route('dossiers.members.destroy', $this->parent, ['member' => $this->member->id]))
            ->assertOk();

        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.index', $this->child))
            ->assertOk()
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.role', 'editor')
            ->assertJsonCount(0, 'inherited_members');
        $this->actingAs($this->member)->get($this->route('dossiers.show', $this->child))->assertOk();
        $this->assertTrue($this->member->can('update', $this->child));
    }

    public function test_explicit_child_anchor_is_displayed_as_direct_while_parent_share_exists(): void
    {
        $this->share($this->parent, DossierMember::ROLE_READER);
        $this->share($this->child, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.index', $this->child))
            ->assertOk()
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.id', $this->member->id)
            ->assertJsonPath('members.0.role', DossierMember::ROLE_EDITOR)
            ->assertJsonCount(0, 'inherited_members');

        $this->assertDatabaseCount('dossier_members', 2);
    }

    public function test_governing_owner_is_never_offered_or_added_on_a_child(): void
    {
        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.search', $this->child, ['q' => $this->owner->email]))
            ->assertOk()
            ->assertJsonCount(0, 'users');

        $this->actingAs($this->owner)
            ->postJson($this->route('dossiers.members.store', $this->child), ['user_id' => $this->owner->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('dossiers.member_is_owner'));

        $this->assertDatabaseMissing('dossier_members', [
            'dossier_id' => $this->child->id,
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_personal_root_and_unshared_sibling_remain_forbidden(): void
    {
        $sibling = Dossier::create([
            'organization_id' => $this->organization->id,
            'parent_id' => $this->personalRoot->id,
            'owner_id' => null,
            'name' => 'Frère privé',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->share($this->parent, DossierMember::ROLE_EDITOR);

        $this->actingAs($this->member)->get($this->route('dossiers.show', $this->personalRoot))->assertForbidden();
        $this->actingAs($this->member)->get($this->route('dossiers.show', $sibling))->assertForbidden();
        $this->actingAs($this->member)->get($this->route('dossiers.show', $this->child))->assertOk();
    }

    public function test_cross_organization_member_never_appears_in_search_or_inherited_access(): void
    {
        $otherOrganization = Organization::factory()->create(['slug' => 'other', 'is_active' => true]);
        $outsider = User::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($this->owner)
            ->getJson($this->route('dossiers.members.search', $this->parent, ['q' => $outsider->email]))
            ->assertOk()
            ->assertJsonCount(0, 'users');

        $this->actingAs($outsider)
            ->get('/org/other/dossiers/'.$this->child->id.'/members')
            ->assertNotFound();
    }

    public function test_share_panel_is_single_and_one_shot_query_is_removed_from_url(): void
    {
        $this->actingAs($this->owner)
            ->get($this->route('dossiers.show', $this->parent, ['partage' => 1]))
            ->assertOk()
            ->assertDontSee('showManageModal')
            ->assertDontSee('manage-members-title')
            ->assertSee('history.replaceState', false)
            ->assertSee('searchQuery', false)
            ->assertSee(__('dossiers.direct_access_title'))
            ->assertSee(__('dossiers.inherited_access_title'));
    }

    private function share(Dossier $dossier, string $role): DossierMember
    {
        return DossierMember::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'user_id' => $this->member->id,
            'role' => $role,
            'added_by' => $this->owner->id,
        ]);
    }

    private function route(string $name, Dossier $dossier, array $extra = []): string
    {
        return route('organization.'.$name, array_merge([
            'organization' => $this->organization->slug,
            'dossier' => $dossier->id,
        ], $extra));
    }
}
