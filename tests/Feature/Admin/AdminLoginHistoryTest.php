<?php

namespace Tests\Feature\Admin;

use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminLoginHistoryTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeOrgAdmin(): array
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_admin' => false, 'organization_id' => $org->id]);
        $org->admin_id = $admin->id;
        $org->save();

        return [$org, $admin];
    }

    // ── Access control (super-admin) ───────────────────────────────────────────

    public function test_guest_cannot_access_login_history(): void
    {
        $this->get(route('admin.stats.login-history'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_login_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.stats.login-history'))->assertStatus(403);
    }

    public function test_admin_can_view_login_history(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history'))
            ->assertOk();
    }

    // ── Login history content (super-admin) ────────────────────────────────────

    public function test_login_history_displays_entries(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Alice Dupont']);
        LoginLog::factory()->count(3)->create(['user_id' => $user->id]);

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history'))
            ->assertOk()
            ->assertSee('Alice Dupont');
    }

    public function test_login_history_shows_org_column_for_super_admin(): void
    {
        $admin = $this->makeAdmin();
        $org = Organization::factory()->create(['name' => 'Beta Corp']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        LoginLog::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history'))
            ->assertOk()
            ->assertSee('Beta Corp');
    }

    public function test_login_history_filters_by_organization(): void
    {
        $admin = $this->makeAdmin();
        $orgA = Organization::factory()->create(['name' => 'Alpha']);
        $orgB = Organization::factory()->create(['name' => 'Beta']);
        $userA = User::factory()->create(['name' => 'AlphaUser', 'organization_id' => $orgA->id]);
        $userB = User::factory()->create(['name' => 'BetaUser', 'organization_id' => $orgB->id]);
        LoginLog::factory()->create(['user_id' => $userA->id, 'organization_id' => $orgA->id]);
        LoginLog::factory()->create(['user_id' => $userB->id, 'organization_id' => $orgB->id]);

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history', ['organization_id' => $orgA->id]))
            ->assertOk()
            ->assertSee('AlphaUser')
            ->assertDontSee('BetaUser');
    }

    public function test_login_history_pagination(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();
        LoginLog::factory()->count(30)->create(['user_id' => $user->id]);

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history'))
            ->assertOk()
            ->assertSee('page=2');
    }

    // ── User detail (super-admin) ──────────────────────────────────────────────

    public function test_admin_can_view_user_login_detail(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Bob Martin']);
        LoginLog::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history.user', $user))
            ->assertOk()
            ->assertSee('Bob Martin');
    }

    // ── Org-admin access control ───────────────────────────────────────────────

    public function test_org_admin_cannot_access_global_login_history(): void
    {
        [$org, $admin] = $this->makeOrgAdmin();

        $this->actingAs($admin)
            ->get(route('admin.stats.login-history'))
            ->assertStatus(403);
    }

    public function test_org_admin_can_access_org_login_history(): void
    {
        [$org, $admin] = $this->makeOrgAdmin();

        $this->actingAs($admin)
            ->get(route('organization.admin.stats.login-history', $org->slug))
            ->assertOk();
    }

    public function test_org_admin_cannot_see_other_org_login_history(): void
    {
        [$orgA, $adminA] = $this->makeOrgAdmin();
        $orgB = Organization::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        LoginLog::factory()->create(['user_id' => $userB->id, 'organization_id' => $orgB->id]);

        $this->actingAs($adminA)
            ->get(route('organization.admin.stats.login-history', $orgA->slug))
            ->assertOk()
            ->assertDontSee($userB->name);
    }

    public function test_org_admin_login_history_is_scoped(): void
    {
        [$org, $admin] = $this->makeOrgAdmin();
        $userInOrg = User::factory()->create(['name' => 'In Org', 'organization_id' => $org->id]);
        LoginLog::factory()->create(['user_id' => $userInOrg->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('organization.admin.stats.login-history', $org->slug))
            ->assertOk()
            ->assertSee('In Org');
    }

    // ── Org-admin user detail ──────────────────────────────────────────────────

    public function test_org_admin_can_view_user_login_detail(): void
    {
        [$org, $admin] = $this->makeOrgAdmin();
        $user = User::factory()->create(['name' => 'Carol', 'organization_id' => $org->id]);
        LoginLog::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('organization.admin.stats.login-history.user', [$org->slug, $user]))
            ->assertOk()
            ->assertSee('Carol');
    }

    public function test_org_admin_cannot_view_user_detail_from_other_org(): void
    {
        [$org, $admin] = $this->makeOrgAdmin();
        $orgB = Organization::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $this->actingAs($admin)
            ->get(route('organization.admin.stats.login-history.user', [$org->slug, $userB]))
            ->assertStatus(404);
    }

    // ── Listener ───────────────────────────────────────────────────────────────

    public function test_login_creates_log_entry(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'password' => bcrypt('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('login_logs', [
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_login_without_org_does_not_create_log(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'password' => bcrypt('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseMissing('login_logs', [
            'user_id' => $user->id,
        ]);
    }

    public function test_failed_login_does_not_create_log(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'password' => bcrypt('correct_password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong_password',
        ]);

        $this->assertDatabaseMissing('login_logs', [
            'user_id' => $user->id,
        ]);
    }
}
