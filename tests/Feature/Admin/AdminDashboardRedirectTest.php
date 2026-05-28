<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDashboardRedirectTest extends TestCase
{
    public function test_admin_with_organization_gets_user_dashboard_on_dashboard_url(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Tableau de bord');
    }

    public function test_dashboard_and_admin_dashboard_are_distinct_for_admin_with_organization(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard');
    }

    public function test_admin_without_organization_does_not_get_user_or_admin_dashboard_on_dashboard_url(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertNotFound();
    }

    public function test_admin_dashboard_destination_is_valid_for_admin_without_organization(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_member_with_organization_still_accesses_member_dashboard(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $member = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Tableau de bord');
    }

    public function test_member_without_organization_does_not_get_admin_dashboard_bypass(): void
    {
        $member = User::factory()->create([
            'is_admin' => false,
            'organization_id' => null,
        ]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertNotFound();
    }

    public function test_admin_with_organization_login_defaults_to_dashboard_url(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $organization->id,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_tenantless_admin_login_defaults_to_admin_dashboard_url(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => null,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard', absolute: false));
    }
}
