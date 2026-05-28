<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\Loop;
use App\Models\LoopMember;
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
        $admin = $this->makeAdmin(['organization_id' => $orgA->id, 'organization_id' => $orgA->id]);

        $loopA = $this->makeLoop($orgA);
        $this->addMember($loopA, $admin);

        $orgB = $this->makeOrg();
        $adminB = $this->makeAdmin(['organization_id' => $orgB->id, 'organization_id' => $orgB->id]);
        $loopB = $this->makeLoop($orgB);
        $this->addMember($loopB, $adminB);

        $response = $this->actingAs($admin)->get(route('admin.loops'));

        $response->assertOk();
        $response->assertSee($loopA->name);
        $response->assertDontSee($loopB->name);
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

    public function test_admin_cannot_see_other_organization_loops(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $admin = $this->makeAdmin(['organization_id' => $orgA->id, 'organization_id' => $orgA->id]);

        $loopB = $this->makeLoop($orgB);

        $otherMember = User::factory()->create(['organization_id' => $orgB->id, 'organization_id' => $orgB->id]);
        $this->addMember($loopB, $otherMember);

        $response = $this->actingAs($admin)->get(route('admin.loops'));
        $response->assertOk();
        $response->assertDontSee($loopB->name);
    }
}
