<?php

namespace Tests\Feature\Admin;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminLoopsTest extends TestCase
{
    private function makeAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['is_admin' => true], $overrides));
    }

    private function makeOrg(): Organization
    {
        return Organization::factory()->create(['is_active' => true]);
    }

    private function makeLoop(Organization $org, ?User $creator = null): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $creator?->id ?? User::factory(),
            'type' => 'team',
            'status' => 'active',
        ]);
    }

    private function addMember(Loop $loop, User $user): LoopMember
    {
        return LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_admin_loops(): void
    {
        $this->get(route('admin.loops'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_loops(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.loops'))->assertStatus(403);
    }

    public function test_admin_can_access_admin_loops(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.loops'))->assertOk();
    }

    // ── Tenant scoping ────────────────────────────────────────────────────────

    public function test_admin_sees_only_own_organization_loops(): void
    {
        $orgA = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $orgA->id]);

        $loopA = $this->makeLoop($orgA);
        $this->addMember($loopA, $admin);

        $orgB = $this->makeOrg();
        $adminB = $this->makeAdmin(['organization_id' => $orgB->id]);
        $loopB = $this->makeLoop($orgB);
        $this->addMember($loopB, $adminB);

        $response = $this->actingAs($admin)->get(route('admin.loops'));

        $response->assertOk();
        $response->assertSee($loopA->name);
        $response->assertSee($loopB->name);
    }

    public function test_admin_loops_default_filter_shows_all_orgs_for_superadmin(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        $defaultOrg = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $orgA = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $orgA->id]);

        $defaultLoop = $this->makeLoop($defaultOrg);
        $loopA = $this->makeLoop($orgA);
        $this->addMember($loopA, $admin);

        $response = $this->actingAs($admin)->get(route('admin.loops'));

        $response->assertOk();
        $response->assertSee($loopA->name);
        $response->assertSee($defaultLoop->name);
    }

    public function test_empty_state_when_no_loops(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.loops'))
            ->assertOk()
            ->assertSee('Aucune boucle');
    }

    public function test_admin_loops_page_shows_member_count(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $loop = $this->makeLoop($org);
        $this->addMember($loop, $admin);
        $this->addMember($loop, $member);

        $this->actingAs($admin)
            ->get(route('admin.loops'))
            ->assertOk()
            ->assertSee('2');
    }

    public function test_admin_loops_page_shows_creator_name(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $loop = Loop::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $admin->id,
            'type' => 'social',
            'status' => 'active',
            'name' => 'Boucle créée par admin',
        ]);

        $this->addMember($loop, $admin);

        $this->actingAs($admin)
            ->get(route('admin.loops'))
            ->assertOk()
            ->assertSee('Boucle créée par admin')
            ->assertSee($admin->name);
    }

    public function test_admin_loops_page_shows_linked_organization(): void
    {
        $org = Organization::factory()->create([
            'name' => 'Visible Loop Organization',
            'is_active' => true,
        ]);
        $admin = $this->makeAdmin(['organization_id' => $org->id]);

        $this->makeLoop($org, $admin);

        $this->actingAs($admin)
            ->get(route('admin.loops'))
            ->assertOk()
            ->assertSee('Organisation')
            ->assertSee('Visible Loop Organization')
            ->assertSee($org->id);
    }

    public function test_admin_loop_edit_shows_read_only_organization_without_reassignment_field(): void
    {
        $org = Organization::factory()->create([
            'name' => 'Read Only Loop Organization',
            'is_active' => true,
        ]);
        $admin = $this->makeAdmin(['organization_id' => $org->id]);
        $loop = $this->makeLoop($org, $admin);

        $this->actingAs($admin)
            ->get(route('admin.loops.edit', $loop))
            ->assertOk()
            ->assertSee('Organisation liée')
            ->assertSee('Read Only Loop Organization')
            ->assertSee($org->id)
            ->assertSee('Lecture seule')
            ->assertDontSee('name="organization_id"', false);
    }

    public function test_superadmin_sees_all_loops_including_other_orgs(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $admin = $this->makeAdmin(['organization_id' => $orgA->id]);

        $loopB = $this->makeLoop($orgB);

        $otherMember = User::factory()->create(['organization_id' => $orgB->id]);
        $this->addMember($loopB, $otherMember);

        $response = $this->actingAs($admin)->get(route('admin.loops'));
        $response->assertOk();
        $response->assertSee($loopB->name);
    }

    // ── SuperAdmin cross-org ─────────────────────────────────────────────────

    public function test_superadmin_sees_all_organizations_loops_by_default(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();
        $superAdmin = $this->makeAdmin(['organization_id' => null]);

        $loopA = $this->makeLoop($orgA);
        $loopB = $this->makeLoop($orgB);

        $response = $this->actingAs($superAdmin)->get(route('admin.loops'));

        $response->assertOk();
        $response->assertSee($loopA->name);
        $response->assertSee($loopB->name);
    }

    public function test_superadmin_can_filter_by_organization(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();
        $superAdmin = $this->makeAdmin();

        $loopA = $this->makeLoop($orgA);
        $loopB = $this->makeLoop($orgB);

        $response = $this->actingAs($superAdmin)->get(route('admin.loops', ['organization_id' => $orgA->id]));

        $response->assertOk();
        $response->assertSee($loopA->name);
        $response->assertDontSee($loopB->name);
    }

    public function test_superadmin_accesses_any_loop_edit(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();
        $superAdmin = $this->makeAdmin(['organization_id' => null]);

        $loopA = $this->makeLoop($orgA);
        $loopB = $this->makeLoop($orgB);

        $this->actingAs($superAdmin)
            ->get(route('admin.loops.edit', $loopA))
            ->assertOk();

        $this->actingAs($superAdmin)
            ->get(route('admin.loops.edit', $loopB))
            ->assertOk();
    }

    public function test_superadmin_can_create_loop_in_any_organization(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();
        $superAdmin = $this->makeAdmin(['organization_id' => $orgA->id]);
        $ownerInOrgB = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_admin' => false,
        ]);

        $response = $this->actingAs($superAdmin)->post(route('admin.loops.store'), [
            'name' => 'SuperAdmin Loop in OrgB',
            'description' => 'Created across orgs',
            'visibility' => 'private',
            'owner_id' => $ownerInOrgB->id,
            'organization_id' => $orgB->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('loops', [
            'name' => 'SuperAdmin Loop in OrgB',
            'organization_id' => $orgB->id,
        ]);
    }

    // ── Archive / Restore ─────────────────────────────────────────────────────

    public function test_superadmin_can_archive_loop(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeAdmin();
        $loop = $this->makeLoop($org);

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.archive', $loop))
            ->assertRedirect();

        $this->assertDatabaseHas('loops', [
            'id' => $loop->id,
            'status' => 'archived',
        ]);
    }

    public function test_superadmin_can_restore_archived_loop(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeAdmin();
        $loop = Loop::factory()->archived()->create([
            'organization_id' => $org->id,
            'created_by' => $superAdmin->id,
            'type' => 'team',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.restore', $loop))
            ->assertRedirect();

        $this->assertDatabaseHas('loops', [
            'id' => $loop->id,
            'status' => 'active',
        ]);
    }

    public function test_org_admin_can_archive_own_org_loop(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id]);
        $loop = $this->makeLoop($org);

        $this->actingAs($admin)
            ->post(route('admin.loops.archive', $loop))
            ->assertRedirect();

        $this->assertDatabaseHas('loops', [
            'id' => $loop->id,
            'status' => 'archived',
        ]);
    }

    public function test_archiving_an_active_loop_sets_status_to_archived(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeAdmin();
        $loop = $this->makeLoop($org);

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.archive', $loop))
            ->assertRedirect();

        $loop->refresh();
        $this->assertTrue($loop->isArchived());
    }

    public function test_restoring_an_archived_loop_sets_status_to_active(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeAdmin();
        $loop = Loop::factory()->archived()->create([
            'organization_id' => $org->id,
            'created_by' => $superAdmin->id,
            'type' => 'team',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.loops.restore', $loop))
            ->assertRedirect();

        $loop->refresh();
        $this->assertTrue($loop->isActive());
    }

    // ── Destroy protection ────────────────────────────────────────────────────

    public function test_cannot_destroy_loop_with_messages(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id]);
        $loop = $this->makeLoop($org);

        \App\Models\LoopMessage::factory()->create([
            'loop_id' => $loop->id,
            'sender_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.loops.destroy', $loop))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('loops', ['id' => $loop->id]);
    }

    public function test_can_destroy_empty_loop(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id]);
        $loop = $this->makeLoop($org);

        $this->actingAs($admin)
            ->delete(route('admin.loops.destroy', $loop))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('loops', ['id' => $loop->id]);
    }

    public function test_has_content_returns_true_when_loop_has_messages(): void
    {
        $org = $this->makeOrg();
        $loop = $this->makeLoop($org);
        $user = User::factory()->create();

        \App\Models\LoopMessage::factory()->create([
            'loop_id' => $loop->id,
            'sender_id' => $user->id,
        ]);

        $this->assertTrue($loop->hasContent());
    }

    public function test_has_content_returns_false_when_loop_has_no_messages(): void
    {
        $org = $this->makeOrg();
        $loop = $this->makeLoop($org);

        $this->assertFalse($loop->hasContent());
    }
}
