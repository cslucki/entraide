<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminUserCreateTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_create_user_form(): void
    {
        $this->get(route('admin.users.create'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_create_user_form(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.users.create'))->assertStatus(403);
    }

    public function test_admin_can_view_create_user_form(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_admin_can_create_user(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main', 'is_active' => true]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Alice Dupont',
                'email' => 'alice@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => false,
                'points' => 0,
            ])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'points_balance' => 0,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_admin_can_create_user_with_points(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Bob Martin',
                'email' => 'bob@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'points' => 150,
            ]);

        $user = User::where('email', 'bob@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(150, $user->points_balance);
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'delta' => 150,
            'reason' => 'welcome_bonus',
        ]);
    }

    public function test_admin_can_create_admin_user(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Super Admin',
                'email' => 'super@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => '1',
                'points' => 0,
            ]);

        $this->assertDatabaseHas('users', ['email' => 'super@example.com', 'is_admin' => true]);
    }

    public function test_create_user_validates_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [])
            ->assertSessionHasErrors(['name', 'email', 'password', 'points']);
    }

    public function test_create_user_rejects_duplicate_email(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Test',
                'email' => 'taken@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'points' => 0,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_create_user_no_ledger_entry_when_zero_points(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Zero',
                'email' => 'zero@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'points' => 0,
            ]);

        $user = User::where('email', 'zero@example.com')->first();
        $this->assertNotNull($user);
        $this->assertDatabaseMissing('point_ledger', ['user_id' => $user->id]);
    }

    // ── Change password ───────────────────────────────────────────────────────

    public function test_admin_can_change_user_password(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.password', $target), [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_change_password_validates_confirmation(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.password', $target), [
                'password' => 'newpassword123',
                'password_confirmation' => 'differentpassword',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_change_password_validates_min_length(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.password', $target), [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_non_admin_cannot_change_password(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.users.password', $target), [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertStatus(403);
    }
}
