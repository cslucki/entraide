<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\OrganizationSetting;
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

    public function test_guest_cannot_access_settings(): void
    {
        $this->get(route('admin.settings'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.settings'))->assertStatus(403);
    }

    public function test_admin_can_view_settings_page(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
    }

    // ── OrganizationSetting model ─────────────────────────────────────────────

    public function test_setting_get_returns_default_when_missing(): void
    {
        $org = Organization::factory()->create();
        $this->assertNull(OrganizationSetting::get($org->id, 'nonexistent'));
        $this->assertSame('default', OrganizationSetting::get($org->id, 'nonexistent', 'default'));
    }

    public function test_setting_set_creates_record(): void
    {
        $org = Organization::factory()->create();
        OrganizationSetting::set($org->id, 'platform_name', 'TestPlatform');
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $org->id, 'key' => 'platform_name', 'value' => 'TestPlatform']);
    }

    public function test_setting_set_updates_existing_record(): void
    {
        $org = Organization::factory()->create();
        OrganizationSetting::set($org->id, 'platform_name', 'First');
        OrganizationSetting::set($org->id, 'platform_name', 'Second');
        $this->assertSame('Second', OrganizationSetting::get($org->id, 'platform_name'));
        $this->assertDatabaseCount('organization_settings', 1);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_admin_can_update_settings(): void
    {
        $admin = $this->makeAdmin();
        $orgId = $admin->organization_id;

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'platform_name'    => 'Nouveau nom',
                'platform_tagline' => 'Nouvelle tagline',
                'maintenance_mode' => '0',
            ])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('success');

        $this->assertSame('Nouveau nom', OrganizationSetting::get($orgId, 'platform_name'));
        $this->assertSame('Nouvelle tagline', OrganizationSetting::get($orgId, 'platform_tagline'));
        $this->assertSame('0', OrganizationSetting::get($orgId, 'maintenance_mode'));
    }

    public function test_update_settings_validates_platform_name_required(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), ['platform_tagline' => 'ok'])
            ->assertSessionHasErrors('platform_name');
    }

    public function test_admin_can_enable_maintenance_mode(): void
    {
        $admin = $this->makeAdmin();
        $orgId = $admin->organization_id;

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'platform_name'    => 'Entraide',
                'maintenance_mode' => '1',
            ]);

        $this->assertSame('1', OrganizationSetting::get($orgId, 'maintenance_mode'));
    }
}
