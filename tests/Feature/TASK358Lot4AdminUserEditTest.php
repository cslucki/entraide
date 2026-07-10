<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class TASK358Lot4AdminUserEditTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_user_edit_displays_structured_fields(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create(['show_country' => true]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'first_name' => 'John',
            'city' => 'Paris',
            'country_code' => 'FR',
            'preferred_locale' => 'fr',
            'address_line1' => 'Private Street',
            'address_line2' => 'Private Suite',
            'postal_code' => '75000',
            'location' => 'Legacy Hidden',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee('John')
            ->assertSee('Paris')
            ->assertSee('France')
            ->assertSee('Français')
            ->assertSee('Private Street')
            ->assertSee('Private Suite')
            ->assertSee('75000')
            ->assertSee('Legacy Hidden')
            ->assertDontSee('name="location"');
    }

    public function test_admin_user_update_saves_new_fields_without_modifying_location(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create(['show_country' => true]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Old Name',
            'location' => 'Original Legacy',
            'city' => null,
            'country_code' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $user))
            ->put(route('admin.users.update', $user), [
                'name' => 'Updated Name',
                'email' => $user->email,
                'first_name' => 'UpdatedFirstName',
                'city' => 'Lyon',
                'country_code' => 'FR',
                'preferred_locale' => 'en',
                'address_line1' => '1 Rue Test',
                'address_line2' => 'Appt 2',
                'postal_code' => '69000',
                'phone' => '0123456789',
                'bio' => 'Updated bio',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('UpdatedFirstName', $user->first_name);
        $this->assertSame('Lyon', $user->city);
        $this->assertSame('FR', $user->country_code);
        $this->assertSame('en', $user->preferred_locale);
        $this->assertSame('1 Rue Test', $user->address_line1);
        $this->assertSame('Appt 2', $user->address_line2);
        $this->assertSame('69000', $user->postal_code);
        $this->assertSame('0123456789', $user->phone);
        $this->assertSame('Updated bio', $user->bio);
        $this->assertSame('Original Legacy', $user->location);
    }

    public function test_admin_user_update_rejects_invalid_country_code(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $user))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'country_code' => 'XYZ',
            ])
            ->assertSessionHasErrors('country_code');

        $this->assertEquals('FR', $user->fresh()->country_code);
    }

    public function test_admin_user_update_rejects_invalid_preferred_locale(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $user))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'preferred_locale' => 'de',
            ])
            ->assertSessionHasErrors('preferred_locale');

        $this->assertNull($user->fresh()->preferred_locale);
    }

    public function test_admin_user_update_inactive_country_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        Country::where('code', 'FR')->update(['active' => false]);
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $user))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'country_code' => 'FR',
            ])
            ->assertSessionHasErrors('country_code');

        Country::where('code', 'FR')->update(['active' => true]);
    }

    public function test_admin_user_update_saves_membership_when_org_has_membership_enabled(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create([
            'membership_enabled' => true,
            'membership_label_en' => 'Cohort',
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'membership_value' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $user))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'organization_id' => $organization->id,
                'membership_value' => 'Promo 2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Promo 2026', $user->fresh()->membership_value);
    }

    public function test_admin_user_update_membership_ignored_when_membership_is_disabled(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create(['membership_enabled' => false]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'membership_value' => 'Should stay',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $user))
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'organization_id' => $organization->id,
                'membership_value' => 'Should not save',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Should stay', $user->fresh()->membership_value);
    }
}
