<?php

namespace Tests\Feature\Admin;

use App\Models\Community;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDashboardRedirectTest extends TestCase
{
    public function test_admin_without_organization_can_access_dashboard_url_without_redirect(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'community_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('admin.dashboard');
    }

    public function test_admin_dashboard_access_only_matches_exact_dashboard_path(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'community_id' => null,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard/anything')
            ->assertNotFound();
    }

    public function test_admin_dashboard_destination_is_valid_for_admin_without_organization(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'community_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_member_with_organization_still_accesses_member_dashboard(): void
    {
        $organization = Community::factory()->create(['is_active' => true]);
        $member = User::factory()->create([
            'is_admin' => false,
            'community_id' => $organization->id,
        ]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Tableau de bord');
    }

    public function test_member_without_organization_does_not_get_admin_dashboard_bypass(): void
    {
        $member = User::factory()->create([
            'is_admin' => false,
            'community_id' => null,
        ]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertNotFound();
    }

    public function test_tenantless_admin_login_defaults_to_dashboard_url(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'community_id' => null,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }
}
