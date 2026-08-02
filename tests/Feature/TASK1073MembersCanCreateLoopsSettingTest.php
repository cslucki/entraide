<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TASK1073MembersCanCreateLoopsSettingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_column_defaults_to_true_on_existing_organizations(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue($organization->fresh()->members_can_create_loops);
    }

    public function test_members_can_create_loops_is_cast_to_boolean(): void
    {
        $organization = Organization::factory()->create(['members_can_create_loops' => 1]);

        $this->assertIsBool($organization->fresh()->members_can_create_loops);
        $this->assertTrue($organization->fresh()->members_can_create_loops);
    }

    public function test_organization_members_can_create_loops_helper_combines_both_flags(): void
    {
        $enabled = Organization::factory()->create(['loops_enabled' => true, 'members_can_create_loops' => true]);
        $this->assertTrue($enabled->membersCanCreateLoops());

        $loopsDisabled = Organization::factory()->create(['loops_enabled' => false, 'members_can_create_loops' => true]);
        $this->assertFalse($loopsDisabled->membersCanCreateLoops());

        $settingDisabled = Organization::factory()->create(['loops_enabled' => true, 'members_can_create_loops' => false]);
        $this->assertFalse($settingDisabled->membersCanCreateLoops());
    }

    public function test_admin_org_edit_displays_the_setting(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create([
            'platform_name' => 'Test Platform',
            'global_color_mode' => 'light',
            'members_can_create_loops' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee(__('admin.members_can_create_loops_label'));
    }

    public function test_admin_org_update_saves_members_can_create_loops_flag(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create([
            'platform_name' => 'Test Platform',
            'global_color_mode' => 'light',
            'members_can_create_loops' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'members_can_create_loops' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($organization->fresh()->members_can_create_loops);
    }

    public function test_admin_org_update_preserves_flag_when_checkbox_checked(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create([
            'platform_name' => 'Test Platform',
            'global_color_mode' => 'light',
            'members_can_create_loops' => false,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'members_can_create_loops' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($organization->fresh()->members_can_create_loops);
    }
}
