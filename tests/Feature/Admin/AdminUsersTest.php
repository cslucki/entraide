<?php

namespace Tests\Feature\Admin;

use App\Models\PointLedger;
use App\Models\User;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_admin_users(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.users'))->assertStatus(403);
    }

    public function test_admin_can_view_users_list(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->count(3)->create();

        $this->actingAs($admin)->get(route('admin.users'))->assertOk();
    }

    // ── Ban / Unban ───────────────────────────────────────────────────────────

    public function test_admin_can_ban_a_user(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['banned_at' => null]);

        $this->actingAs($admin)
            ->patch(route('admin.users.ban', $user))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function test_admin_cannot_ban_themselves(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.ban', $admin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($admin->fresh()->banned_at);
    }

    public function test_admin_can_unban_a_user(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['banned_at' => now()]);

        $this->actingAs($admin)
            ->patch(route('admin.users.unban', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh()->banned_at);
    }

    // ── Points adjustment ─────────────────────────────────────────────────────

    public function test_admin_can_add_points_to_user(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => 50])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(150, $user->fresh()->points_balance);
    }

    public function test_admin_can_remove_points_from_user(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => -30])
            ->assertRedirect();

        $this->assertSame(70, $user->fresh()->points_balance);
    }

    public function test_adjust_points_writes_to_point_ledger(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => 25]);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'delta'   => 25,
            'reason'  => 'adjustment',
        ]);
    }

    public function test_adjust_points_rejects_zero_delta(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => 0])
            ->assertSessionHasErrors('delta');

        $this->assertSame(100, $user->fresh()->points_balance);
        $this->assertDatabaseMissing('point_ledger', ['user_id' => $user->id, 'reason' => 'adjustment']);
    }

    // ── Toggle admin ──────────────────────────────────────────────────────────

    public function test_admin_can_toggle_admin_rights_of_another_user(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-admin', $user))
            ->assertRedirect();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_admin_cannot_toggle_their_own_admin_rights(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-admin', $admin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_admin);
    }

    // ── Toggle availability ───────────────────────────────────────────────────

    public function test_admin_can_toggle_user_availability(): void
    {
        $admin = $this->makeAdmin();
        $user  = User::factory()->create(['is_available' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-availability', $user))
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_available);
    }
}
