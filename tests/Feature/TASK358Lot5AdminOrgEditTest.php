<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class TASK358Lot5AdminOrgEditTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeOrg(array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'platform_name' => 'Test Platform',
            'global_color_mode' => 'light',
        ], $overrides));
    }

    public function test_admin_org_edit_displays_country_and_membership_fields(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg([
            'default_country_code' => 'FR',
            'show_country' => true,
            'membership_enabled' => true,
            'membership_label_fr' => 'Situé en France ?',
            'membership_label_en' => 'Based in France?',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee('FR')
            ->assertSee('Situé en France ?')
            ->assertSee('Based in France?');
    }

    public function test_admin_org_update_saves_country_and_show_country(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg([
            'default_country_code' => null,
            'show_country' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'default_country_code' => 'FR',
                'show_country' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $organization->refresh();

        $this->assertSame('FR', $organization->default_country_code);
        $this->assertFalse($organization->show_country);
    }

    public function test_admin_org_update_saves_membership_config(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg([
            'membership_enabled' => false,
            'membership_label_fr' => null,
            'membership_label_en' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'membership_enabled' => '1',
                'membership_label_fr' => 'Fait partie de ?',
                'membership_label_en' => 'Part of?',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $organization->refresh();

        $this->assertTrue($organization->membership_enabled);
        $this->assertSame('Fait partie de ?', $organization->membership_label_fr);
        $this->assertSame('Part of?', $organization->membership_label_en);
    }

    public function test_admin_org_update_rejects_invalid_default_country_code(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg(['default_country_code' => null]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'default_country_code' => 'XYZ',
            ])
            ->assertSessionHasErrors('default_country_code');

        $this->assertNull($organization->fresh()->default_country_code);
    }

    public function test_admin_org_update_saves_priority_countries_with_order(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg();

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'priority_country_codes' => ['FR', 'BE', 'CH'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $priorities = $organization->priorityCountries()->get();
        $this->assertCount(3, $priorities);
        $this->assertSame('FR', $priorities[0]->code);
        $this->assertSame('BE', $priorities[1]->code);
        $this->assertSame('CH', $priorities[2]->code);
    }

    public function test_admin_org_update_rejects_invalid_priority_country(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg();

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'priority_country_codes' => ['FR', 'INVALID'],
            ])
            ->assertSessionHasErrors('priority_country_codes.1');

        $this->assertCount(0, $organization->fresh()->priorityCountries);
    }

    public function test_admin_org_update_clears_priority_countries_when_empty(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg();
        $organization->countryPreferences()->create(['country_code' => 'US', 'sort_order' => 0]);

        $this->assertCount(1, $organization->fresh()->priorityCountries);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $organization->welcome_points,
                'priority_country_codes' => [],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertCount(0, $organization->fresh()->priorityCountries);
    }

    public function test_admin_org_update_preserves_existing_fields(): void
    {
        $admin = $this->makeAdmin();
        $organization = $this->makeOrg([
            'name' => 'Original Name',
            'description' => 'Original description',
            'welcome_points' => 500,
            'loops_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => 'Original Name',
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => 500,
                'loops_enabled' => '1',
                'default_country_code' => 'US',
                'show_country' => '0',
                'membership_enabled' => '1',
                'membership_label_fr' => 'Label FR',
                'membership_label_en' => 'Label EN',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $organization->refresh();

        $this->assertSame('Original Name', $organization->name);
        $this->assertSame('Original description', $organization->description);
        $this->assertSame(500, $organization->welcome_points);
        $this->assertTrue($organization->loops_enabled);
        $this->assertSame('US', $organization->default_country_code);
        $this->assertFalse($organization->show_country);
        $this->assertTrue($organization->membership_enabled);
        $this->assertSame('Label FR', $organization->membership_label_fr);
        $this->assertSame('Label EN', $organization->membership_label_en);
    }
}
