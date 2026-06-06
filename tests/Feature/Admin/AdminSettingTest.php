<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminSettingTest extends TestCase
{
    private function makeAdmin(): User
    {
        $org = Organization::factory()->create(['is_active' => true]);

        return User::factory()->create(['is_admin' => true, 'organization_id' => $org->id]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_organizations(): void
    {
        $this->get(route('admin.organizations'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_organizations(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.organizations'))->assertStatus(403);
    }

    public function test_admin_can_view_organizations_page(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.organizations'))->assertOk();
    }

    // ── Organization settings (stored as columns) ─────────────────────────────

    public function test_organization_has_default_settings_on_create(): void
    {
        $org = Organization::factory()->create();
        $org->refresh();

        $this->assertTrue($org->loops_enabled);
        $this->assertFalse($org->maintenance_mode);
        $this->assertSame('dark', $org->global_color_mode);
    }

    // ── Update settings via organization edit ─────────────────────────────────

    public function test_admin_can_update_organization_settings(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'              => $org->name,
                'slug'              => $org->slug,
                'welcome_points'    => $org->welcome_points,
                'platform_name'     => 'Nouveau nom',
                'platform_tagline'  => 'Nouvelle tagline',
                'global_color_mode' => 'light',
                'maintenance_mode'  => '1',
            ])
            ->assertRedirect(route('admin.organizations'))
            ->assertSessionHas('success');

        $org->refresh();

        $this->assertSame('Nouveau nom', $org->platform_name);
        $this->assertSame('Nouvelle tagline', $org->platform_tagline);
        $this->assertSame('light', $org->global_color_mode);
        $this->assertTrue($org->maintenance_mode);
    }

    public function test_update_settings_validates_platform_name_required(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'           => $org->name,
                'slug'           => $org->slug,
                'welcome_points' => $org->welcome_points,
                'platform_name'  => '',
            ])
            ->assertSessionHasErrors('platform_name');
    }

    public function test_admin_can_toggle_loops_enabled(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'              => $org->name,
                'slug'              => $org->slug,
                'welcome_points'    => $org->welcome_points,
                'platform_name'     => 'Entraide',
                'global_color_mode' => 'dark',
            ]);

        $org->refresh();
        $this->assertFalse($org->loops_enabled);
    }

    // ── Service points range ──────────────────────────────────────────────────

    public function test_admin_can_set_service_points_range(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'               => $org->name,
                'slug'               => $org->slug,
                'welcome_points'     => $org->welcome_points,
                'platform_name'      => 'Entraide',
                'global_color_mode'  => 'dark',
                'service_points_min' => '10',
                'service_points_max' => '120',
            ])
            ->assertRedirect(route('admin.organizations'));

        $org->refresh();
        $this->assertSame(10, $org->service_points_min);
        $this->assertSame(120, $org->service_points_max);
    }

    public function test_service_points_max_must_be_gte_min(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'               => $org->name,
                'slug'               => $org->slug,
                'welcome_points'     => $org->welcome_points,
                'platform_name'      => 'Entraide',
                'global_color_mode'  => 'dark',
                'service_points_min' => '100',
                'service_points_max' => '50',
            ])
            ->assertSessionHasErrors('service_points_max');
    }

    public function test_service_points_range_is_optional(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'               => $org->name,
                'slug'               => $org->slug,
                'welcome_points'     => $org->welcome_points,
                'platform_name'      => 'Entraide',
                'global_color_mode'  => 'dark',
            ])
            ->assertRedirect(route('admin.organizations'));

        $org->refresh();
        $this->assertNull($org->service_points_min);
        $this->assertNull($org->service_points_max);
    }

    // ── Header JavaScript ─────────────────────────────────────────────────────

    public function test_admin_can_enable_and_set_header_javascript(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $js = '<script>console.log("test");</script>';

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'                     => $org->name,
                'slug'                     => $org->slug,
                'welcome_points'           => $org->welcome_points,
                'platform_name'            => 'Entraide',
                'global_color_mode'        => 'dark',
                'header_javascript_enabled' => '1',
                'header_javascript'        => $js,
            ])
            ->assertRedirect(route('admin.organizations'));

        $org->refresh();
        $this->assertTrue($org->header_javascript_enabled);
        $this->assertSame($js, $org->header_javascript);
    }

    public function test_header_javascript_defaults_to_disabled(): void
    {
        $admin = $this->makeAdmin();
        $org = $admin->organization;

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name'               => $org->name,
                'slug'               => $org->slug,
                'welcome_points'     => $org->welcome_points,
                'platform_name'      => 'Entraide',
                'global_color_mode'  => 'dark',
            ])
            ->assertRedirect(route('admin.organizations'));

        $org->refresh();
        $this->assertFalse($org->header_javascript_enabled);
        $this->assertNull($org->header_javascript);
    }
}
