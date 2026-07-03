<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class TASK358Lot2ProfileEditTest extends TestCase
{
    public function test_profile_edit_uses_structured_fields_without_location_input(): void
    {
        $user = $this->createUserForProfile();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk()
            ->assertDontSee('name="location"', false)
            ->assertDontSee('id="location"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="city"', false)
            ->assertSee('name="country_code"', false)
            ->assertSee('name="preferred_locale"', false)
            ->assertSee('name="address_line1"', false)
            ->assertSee('name="address_line2"', false)
            ->assertSee('name="postal_code"', false);
    }

    public function test_profile_update_saves_new_fields_without_modifying_legacy_location(): void
    {
        $user = $this->createUserForProfile(['location' => 'Legacy Location']);

        $response = $this->actingAs($user)->put(route('profile.update'), $this->validPayload([
            'first_name' => 'Cyril',
            'name' => 'Durand',
            'bio' => 'Bio updated for the structured profile.',
            'city' => 'Paris',
            'country_code' => 'FR',
            'preferred_locale' => 'fr',
            'address_line1' => '12 rue Exemple',
            'address_line2' => 'Batiment B',
            'postal_code' => '75001',
            'location' => 'Should not be saved',
        ]));

        $response->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Cyril', $user->first_name);
        $this->assertSame('Durand', $user->name);
        $this->assertSame('Paris', $user->city);
        $this->assertSame('FR', $user->country_code);
        $this->assertSame('fr', $user->preferred_locale);
        $this->assertSame('12 rue Exemple', $user->address_line1);
        $this->assertSame('Batiment B', $user->address_line2);
        $this->assertSame('75001', $user->postal_code);
        $this->assertSame('Legacy Location', $user->location);
    }

    public function test_profile_update_rejects_invalid_country_code(): void
    {
        $user = $this->createUserForProfile();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->put(route('profile.update'), $this->validPayload(['country_code' => 'XX']))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('country_code');
    }

    public function test_profile_update_rejects_invalid_preferred_locale(): void
    {
        $user = $this->createUserForProfile();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->put(route('profile.update'), $this->validPayload(['preferred_locale' => 'de']))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('preferred_locale');
    }

    public function test_private_address_fields_are_saved_and_not_displayed_on_public_profile(): void
    {
        $user = $this->createUserForProfile();

        $this->actingAs($user)
            ->put(route('profile.update'), $this->validPayload([
                'address_line1' => '99 Private Street',
                'address_line2' => 'Private Floor',
                'postal_code' => '12345',
            ]))
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('99 Private Street', $user->address_line1);
        $this->assertSame('Private Floor', $user->address_line2);
        $this->assertSame('12345', $user->postal_code);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertDontSee('99 Private Street')
            ->assertDontSee('Private Floor')
            ->assertDontSee('12345');
    }

    public function test_membership_field_is_visible_and_saved_when_enabled(): void
    {
        $organization = Organization::factory()->create([
            'membership_enabled' => true,
            'membership_label_fr' => 'Promotion',
            'membership_label_en' => 'Cohort',
        ]);
        app()->instance('current_organization', $organization);

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('name="membership_value"', false)
            ->assertSee('Cohort');

        $this->actingAs($user)
            ->put(route('profile.update'), $this->validPayload(['membership_value' => 'Promo 2026'], $user))
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Promo 2026', $user->refresh()->membership_value);
    }

    public function test_membership_field_is_absent_and_ignored_when_disabled(): void
    {
        $user = $this->createUserForProfile(['membership_value' => 'Existing value']);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('name="membership_value"', false);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->validPayload(['membership_value' => 'Should be ignored'], $user))
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Existing value', $user->refresh()->membership_value);
    }

    public function test_priority_countries_appear_before_other_active_countries(): void
    {
        app()->setLocale('en');

        $organization = Organization::factory()->create();
        app()->instance('current_organization', $organization);
        $organization->priorityCountries()->attach('US', ['sort_order' => 0]);

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $content = $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($content, 'value="FR"'),
            strpos($content, 'value="US"')
        );
    }

    public function test_default_country_appears_first_then_priority_countries_then_others(): void
    {
        app()->setLocale('en');

        $organization = Organization::factory()->create([
            'default_country_code' => 'DE',
        ]);
        app()->instance('current_organization', $organization);
        $organization->priorityCountries()->attach('US', ['sort_order' => 0]);
        $organization->priorityCountries()->attach('GB', ['sort_order' => 1]);

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $content = $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $posDE = strpos($content, 'value="DE"');
        $posUS = strpos($content, 'value="US"');
        $posGB = strpos($content, 'value="GB"');
        $posFR = strpos($content, 'value="FR"');

        $this->assertNotFalse($posDE, 'Default country DE must appear in the list');
        $this->assertNotFalse($posUS, 'Priority country US must appear in the list');
        $this->assertNotFalse($posGB, 'Priority country GB must appear in the list');

        // DE (default) should appear before US and GB (priority)
        $this->assertLessThan($posUS, $posDE);
        $this->assertLessThan($posGB, $posDE);

        // US and GB (priority) should appear before FR (other)
        $this->assertLessThan($posFR, $posUS);
        $this->assertLessThan($posFR, $posGB);

        // Default country must not appear as a duplicate
        $this->assertEquals(1, substr_count($content, 'value="DE"'));
    }

    private function createUserForProfile(array $attributes = []): User
    {
        $organization = Organization::factory()->create();
        app()->instance('current_organization', $organization);

        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
        ], $attributes));
    }

    private function validPayload(array $overrides = [], ?User $user = null): array
    {
        return array_merge([
            'first_name' => 'Jean',
            'name' => $user?->name ?? 'Dupont',
            'email' => $user?->email ?? 'jean.dupont@example.test',
            'phone' => '0612345678',
            'bio' => 'A short profile biography.',
            'city' => 'Paris',
            'country_code' => Country::query()->whereKey('FR')->exists() ? 'FR' : null,
            'preferred_locale' => 'fr',
            'address_line1' => '1 rue Exemple',
            'address_line2' => null,
            'postal_code' => '75000',
            'website' => null,
            'linkedin_url' => null,
        ], $overrides);
    }
}
