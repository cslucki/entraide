<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class TASK358Lot3PublicLocationTest extends TestCase
{
    public function test_public_profile_does_not_show_legacy_location_and_shows_city_country(): void
    {
        $organization = $this->createOrganization(['show_country' => true]);
        $user = $this->createStructuredUser($organization, [
            'city' => 'Paris',
            'country_code' => 'FR',
            'location' => 'Legacy Secret Location',
        ]);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Paris, France')
            ->assertDontSee('Legacy Secret Location')
            ->assertDontSee($user->address_line1)
            ->assertDontSee($user->address_line2)
            ->assertDontSee($user->postal_code);
    }

    public function test_public_profile_shows_city_only_when_show_country_is_disabled(): void
    {
        $organization = $this->createOrganization(['show_country' => false]);
        $user = $this->createStructuredUser($organization, [
            'city' => 'Lyon',
            'country_code' => 'FR',
            'location' => 'Legacy Lyon',
        ]);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Lyon')
            ->assertDontSee('Lyon, France')
            ->assertDontSee('Legacy Lyon');
    }

    public function test_public_profile_has_no_legacy_location_fallback_when_city_is_empty(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createStructuredUser($organization, [
            'city' => null,
            'country_code' => 'FR',
            'location' => 'Legacy Fallback Should Stay Hidden',
        ]);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertDontSee('Legacy Fallback Should Stay Hidden')
            ->assertDontSee('France');
    }

    public function test_members_directory_does_not_show_legacy_location_or_private_address(): void
    {
        $organization = $this->createOrganization();
        $viewer = $this->createStructuredUser($organization, ['name' => 'Viewer']);
        $member = $this->createStructuredUser($organization, [
            'name' => 'Visible Member',
            'city' => 'Marseille',
            'country_code' => 'FR',
            'location' => 'Legacy Member Location',
            'address_line1' => 'Private Member Street',
            'address_line2' => 'Private Member Floor',
            'postal_code' => '13000',
        ]);

        $this->actingAs($viewer)->get(route('members.index'))
            ->assertOk()
            ->assertSee($member->name)
            ->assertSee('Marseille, France')
            ->assertDontSee('Legacy Member Location')
            ->assertDontSee('Private Member Street')
            ->assertDontSee('Private Member Floor')
            ->assertDontSee('13000');
    }

    public function test_search_does_not_show_or_match_legacy_location(): void
    {
        $organization = $this->createOrganization();
        $matchedByCity = $this->createStructuredUser($organization, [
            'name' => 'City Match',
            'city' => 'Paris',
            'country_code' => 'FR',
            'location' => 'Legacy Hidden City',
        ]);
        $this->createStructuredUser($organization, [
            'name' => 'Legacy Only',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'location' => 'Paris Legacy Only',
        ]);

        $this->get(route('search', ['q' => 'Paris']))
            ->assertOk()
            ->assertSee($matchedByCity->name)
            ->assertSee('Paris, France')
            ->assertDontSee('Legacy Hidden City')
            ->assertDontSee('Legacy Only')
            ->assertDontSee('Paris Legacy Only');
    }

    public function test_search_finds_users_by_name_bio_and_city(): void
    {
        $organization = $this->createOrganization();
        $nameUser = $this->createStructuredUser($organization, ['name' => 'Alice Searchable']);
        $bioUser = $this->createStructuredUser($organization, ['name' => 'Bio Match', 'bio' => 'Expert comptable solidaire']);
        $cityUser = $this->createStructuredUser($organization, ['name' => 'City Match', 'city' => 'Nantes']);

        $this->get(route('search', ['q' => 'Alice']))
            ->assertOk()
            ->assertSee($nameUser->name);

        $this->get(route('search', ['q' => 'comptable']))
            ->assertOk()
            ->assertSee($bioUser->name);

        $this->get(route('search', ['q' => 'Nantes']))
            ->assertOk()
            ->assertSee($cityUser->name);
    }

    private function createOrganization(array $attributes = []): Organization
    {
        $organization = Organization::factory()->create(array_merge([
            'show_country' => true,
        ], $attributes));

        app()->instance('current_organization', $organization);

        return $organization;
    }

    private function createStructuredUser(Organization $organization, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'city' => 'Paris',
            'country_code' => 'FR',
            'location' => 'Legacy Location',
            'address_line1' => 'Private Street',
            'address_line2' => 'Private Suite',
            'postal_code' => '75000',
        ], $attributes));
    }
}
